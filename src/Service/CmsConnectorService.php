<?php

namespace MBtec\CmsConnector\Service;

use Zend\Log\LoggerInterface;
use Zend\Log\LoggerAwareInterface;
use Zend\Http\Client as HttpClient;
use Zend\Http\Client\Adapter\Curl as CurlAdapter;
use Zend\Http\Header;
use Zend\Http\PhpEnvironment\Request;
use Zend\Http\Response;
use Zend\Uri\Http as HttpUri;
use Zend\Filter\StaticFilter;
use Zend\View\HelperPluginManager as ViewHelperPluginManager;
use Zend\Cache\Storage\Adapter\AbstractAdapter as CacheAdapter;

/**
 * Class        CmsConnectorService
 * @package     MBtec\CmsConnector\Service
 * @author      Matthias Büsing <info@mb-tec.eu>
 * @copyright   2018 Matthias Büsing
 * @license     http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link        http://mb-tec.eu
 */
class CmsConnectorService implements LoggerAwareInterface
{
    protected $_oViewHelperManager = null;
    protected $_oLogService = null;
    protected $_serviceLocator = null;
    protected $_sPath = null;
    protected $_aValues = [];
    protected $_oEscaper = null;
    protected $_oCache = null;
    protected $_oRequest = null;
    protected $_aConfig = null;
    protected $_bIsErrorLookup = false;

    const TAG_PLUGIN = 'Plugin';
    const TAG_VAR = 'Var';
    const TAG_CONTENT = 'Content';
    const TAG_TEMPLATE = 'Template';
    const TAG_URL = 'Url';

    const CACHE_KEY_HTTP_SESSION = 'cms_session_key';
    const CMS_SESSION_NAME = 'PHPSESSID';

    const LOGFILE = 'mbtec_cmsconnector.log';

    /**
     * @param $oRequest
     *
     * @return $this
     */
    public function setRequest(Request $oRequest)
    {
        $this->_oRequest = $oRequest;

        return $this;
    }

    /**
     * @param array $aConfig
     *
     * @return $this
     */
    public function setConfig(array $aConfig)
    {
        $this->_aConfig = $aConfig;

        return $this;
    }

    /**
     * @param $oViewHelperManager
     *
     * @return $this
     */
    public function setViewHelperManager(ViewHelperPluginManager $oViewHelperManager)
    {
        $this->_oViewHelperManager = $oViewHelperManager;

        return $this;
    }

    /**
     * @param LoggerInterface $oLogger
     *
     * @return $this
     */
    public function setLogger(LoggerInterface $oLogger)
    {
        $this->_oLogService = $oLogger;

        return $this;
    }

    /**
     * @param CacheAdapter $oCache
     *
     * @return $this
     */
    public function setCache(CacheAdapter $oCache)
    {
        $this->_oCache = $oCache;

        return $this;
    }

    /**
     * @param $sPath
     *
     * @return $this
     */
    public function setPath($sPath)
    {
        $this->_sPath = (string) $sPath;

        return $this;
    }

    /**
     * @param $sKey
     * @param $sValue
     *
     * @return $this
     */
    public function assignValue($sKey, $sValue)
    {
        $this->_aValues[$sKey] = $sValue;

        return $this;
    }

    /**
     * @param array $aValues
     *
     * @return $this
     */
    public function assignValues(array $aValues)
    {
        foreach ($aValues as $k => $v) {
            $this->assignValue($k, $v);
        }

        return $this;
    }

    /**
     * @param $isErrorLookup
     *
     * @return $this
     */
    public function setIsErrorLookup($isErrorLookup)
    {
        $this->_bIsErrorLookup = (bool) $isErrorLookup;

        return $this;
    }

    /**
     * @param null $sPath
     *
     * @return bool|mixed|null|Response
     */
    public function getResponse($sPath = null)
    {
        if (is_string($sPath)) {
            $this->setPath($sPath);
        }

        $sPath = $this->_getPath();
        $sPath = trim($sPath, '/');

        if (isset($this->_aConfig['forbidden_dirs']) && is_array($this->_aConfig['forbidden_dirs'])) {
            foreach ($this->_aConfig['forbidden_dirs'] as $sForbiddenDir) {
                if (stripos($sPath, $sForbiddenDir) === 0) {
                    return false;
                }
            }
        }

        $oResponse = $this->_getResponse($sPath);

        if (is_object($oResponse) && $oResponse->isOk()) {
            $oContentTypeHeader = $oResponse->getHeaders()->get('Content-Type');

            if (is_object($oContentTypeHeader)) {
                $sContentType = $oContentTypeHeader->getMediaType();

                if ($sContentType == 'text/html') {
                    $this->_parseContent($oResponse);
                }
            }
        }

        return $oResponse;
    }

    /**
     * @param null $sPath
     *
     * @return string
     */
    public function getTplResponseContent($sPath = null)
    {
        $oResponse = $this->getResponse($sPath);

        if ($oResponse->isOk()) {
            return $oResponse->getContent();
        }

        if (isset($this->_aValues['sContent'])) {
            return $this->_aValues['sContent'];
        }

        return '';
    }

    /**
     * @param $sPath
     *
     * @return mixed|null|Response
     */
    protected function _getResponse($sPath)
    {
        $sCacheKey = 'cms-' . md5(strtolower($sPath));

        $bCacheEnabled = isset($this->_aConfig['cache_enabled'])
            ? (bool) $this->_aConfig['cache_enabled']
            : false;

        $bCacheMaxSize = isset($this->_aConfig['cache_max_size'])
            ? (int) $this->_aConfig['cache_max_size']
            : 1048576;

        $bDoCaching = $bCacheEnabled && !$this->_oRequest->isPost();

        if ($bDoCaching && $this->_oCache->hasItem($sCacheKey)) {
            $bClearCache = (bool) $this->_oRequest->getQuery('clear', false);

            if ($bClearCache) {
                $this->_oCache->removeItem($sCacheKey);
            } else {
                $oResponse = $this->_oCache->getItem($sCacheKey);
            }
        }

        if (!isset($oResponse) || !($oResponse instanceof Response)) {
            $oResponse = $this->_getCmsResponse();

            if ($bDoCaching
                && $oResponse instanceof Response
                && $oResponse->isOk()
                && strlen($oResponse->getContent()) <= $bCacheMaxSize) {
                $this->_oCache->setItem($sCacheKey, $oResponse);
            }
        }

        return $oResponse;
    }

    /**
     * @return null|\Zend\Http\Response
     */
    protected function _getCmsResponse()
    {
        try {
            $oAdapter = new CurlAdapter();
            $oAdapter
                ->setCurlOption(CURLOPT_SSL_VERIFYPEER, false)
                ->setCurlOption(CURLOPT_SSL_VERIFYHOST, false);

            if ($this->_oCache->hasItem(self::CACHE_KEY_HTTP_SESSION)) {
                $sCmsSessionKey = $this->_oCache->getItem(self::CACHE_KEY_HTTP_SESSION);

                $oAdapter->setCurlOption(CURLOPT_COOKIE, self::CMS_SESSION_NAME . '=' . $sCmsSessionKey);
            }

            $sPath = '/' . trim($this->_getPath(), '/');

            if ($this->_aConfig['log']) {
                $this->_oLogService->info($sPath, self::LOGFILE);
            }

            $oUri = new HttpUri();
            $oUri
                ->setScheme($this->_aConfig['scheme'])
                ->setHost($this->_aConfig['host'])
                ->setPath($sPath);

            $oClient = new HttpClient();
            $oClient
                ->setOptions(['timeout' => 30])
                ->setAdapter($oAdapter)
                ->setUri($oUri);

            if ($this->_oRequest->isPost()) {
                $oClient
                    ->setMethod(Request::METHOD_POST)
                    ->setParameterPost(
                        $this->_oRequest->getPost()->toArray()
                    );

                $aFileUpload = $this->_oRequest->getFiles()->toArray();

                if (isset($aFileUpload['fileupload']['tmp_name']) && $aFileUpload['fileupload']['tmp_name'] != '') {
                    $oClient->setFileUpload(
                        $aFileUpload['fileupload']['tmp_name'], 'upload'
                    );
                }
            }

            $oHeader = new Header\AcceptEncoding();
            $oHeader->addEncoding('identity');
            $oClient->getRequest()->getHeaders()->addHeader($oHeader);

            if (isset($this->_aConfig['htaccess_user'])
                && $this->_aConfig['htaccess_user'] != ''
                && isset($this->_aConfig['htaccess_password'])) {
                $oClient->setAuth($this->_aConfig['htaccess_user'], $this->_aConfig['htaccess_password']);
            }

            $oResponse = $oClient->send();

            return $this->_cloneResponse($oResponse);
        } catch (\Exception $oEx) {
            $this->_oLogService->logException($oEx);
        }

        return null;
    }

    /**
     * @param Response $oOrigResponse
     *
     * @return Response
     */
    protected function _cloneResponse(Response $oOrigResponse)
    {
        if (!is_object($oOrigResponse)) {
            return $oOrigResponse;
        }

        $oResponse = new Response();
        $oResponse
            ->setContent($oOrigResponse->getBody())
            ->setStatusCode($oOrigResponse->getStatusCode());
        
        foreach ($oOrigResponse->getHeaders() as $oOrigHeader) {
            if ($oOrigHeader instanceof Header\ContentType
                || $oOrigHeader instanceof Header\Expires
                || $oOrigHeader instanceof Header\CacheControl
                || $oOrigHeader instanceof Header\Pragma) {
                $oHeader = clone $oOrigHeader;

                $oResponse->getHeaders()->addHeader($oHeader);
            } elseif ($oOrigHeader instanceof Header\SetCookie && $oOrigHeader->getName() == self::CMS_SESSION_NAME) {
                $this->_oCache->setItem(self::CACHE_KEY_HTTP_SESSION, $oOrigHeader->getValue());
            }
        }

        return $oResponse;
    }

    /**
     * @param Response $oResponse
     *
     * @return $this
     */
    protected function _parseContent(Response $oResponse)
    {
        $sContent = $oResponse->getContent();

        if ($this->_bIsErrorLookup && preg_match('/{{zTemplate/iU', $sContent)) {
            $oResponse->setStatusCode($oResponse::STATUS_CODE_404);

            return $this;
        }

        $sContent = str_replace('<a href="//"', '<a href="/"', $sContent);

        $sContent = preg_replace_callback(
            '/{{z(Plugin|Var|Content|Template|Url)(.*)}}/iU', [$this, 'parseTag'], $sContent
        );

        $sContent = $this->_injectHeadLink($sContent);
        $sContent = $this->_injectJavaScript($sContent);
        $sContent = $this->_injectBaseHref($sContent);

        $oResponse->setContent($sContent);

        return $this;
    }

    /**
     * @param array $aMatch
     *
     * @return string
     */
    public function parseTag(array $aMatch)
    {
        $sTagType = $aMatch[1];
        $sTagContext = trim($aMatch[2]);

        switch ($sTagType) {
            case self::TAG_PLUGIN:
                return $this->_getPluginData($sTagContext);

            case self::TAG_URL:
                return $this->_getUrl($sTagContext);

            case self::TAG_VAR:
                return $this->_getVarData($sTagContext);

            case self::TAG_CONTENT:
                return $this->_getVarContent();

            case self::TAG_TEMPLATE:
                return '';

            default:
        }

        return '';
    }

    /**
     * @param $sContent
     *
     * @return string
     */
    public function _injectHeadLink($sContent)
    {
        $oHeadLink = $this->_oViewHelperManager->get('headlink');
        $sHeadLink = $oHeadLink->toString();

        $sContent = str_ireplace('{{zHeadLink}}', $sHeadLink, $sContent);

        return $sContent;
    }

    /**
     * @param $sContent
     *
     * @return string
     */
    public function _injectJavaScript($sContent)
    {
        $oInlineScript = $this->_oViewHelperManager->get('inlinescript');
        $sInlineScript = $oInlineScript->toString();

        $sContent = str_ireplace('{{zInlineScript}}', $sInlineScript, $sContent);

        return $sContent;
    }

    /**
     * @param $sContent
     *
     * @return string
     */
    public function _injectBaseHref($sContent)
    {
        $sHref = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/';

        $sContent = str_replace('<base href="/">', sprintf('<base href="%s">', $sHref), $sContent);

        return $sContent;
    }

    /**
     * @param $sTagContext
     *
     * @return string
     */
    protected function _getPluginData($sTagContext)
    {
        if (substr_count($sTagContext, '|') > 0) {
            $aParams = explode('|', $sTagContext);
            $aParams = array_map('trim', $aParams);
            $sPlugin = array_shift($aParams);
        } else {
            $sPlugin = $sTagContext;
        }

        try {
            $oPlugin = $this->_oViewHelperManager->get($sPlugin);
            if (is_object($oPlugin)) {
                if (isset($aParams) && is_array($aParams)) {
                    foreach ($aParams as $sParamString) {
                        $aParamData = explode('=', $sParamString);
                        $sKey = array_shift($aParamData);
                        $sKeyCamelCase = StaticFilter::execute($sKey, 'Word\UnderscoreToCamelCase');
                        $sMethodSet = 'set' . $sKeyCamelCase;
                        if (method_exists($oPlugin, $sMethodSet)) {
                            $aParsedParam = [];
                            foreach ($aParamData as $sValue) {
                                if ($sValue[0] == '$') {
                                    $aParsedParam = $this->_getVarData(substr($sValue, 1));
                                } else {
                                    $aParsedParam = $sValue;
                                }
                            }

                            try {
                                $oPlugin->$sMethodSet($aParsedParam);
                            } catch (\Exception $oEx) {
                                $this->_oLogService->logException($oEx);
                            }
                        }
                    }
                }

                return $oPlugin->__invoke();
            }
        } catch (\Exception $oEx) {
            $this->_oLogService->logException($oEx);
        }

        return '';
    }

    /**
     * @param $sTagContext
     *
     * @return string
     */
    protected function _getUrl($sTagContext)
    {
        try {
            return $this->_oViewHelperManager->get('url')->__invoke($sTagContext);
        } catch (\Exception $oEx) {
            $this->_oLogService->logException($oEx);
        }

        return '';
    }

    /**
     * @param $sTagContext
     *
     * @return mixed|string
     */
    protected function _getVarData($sTagContext)
    {
        $sVarData = '';

        switch (strtolower($sTagContext[0])) {
            case 'o':
                // Object
                $aContextData = explode('.', $sTagContext);
                $sVarKey = $aContextData[0];

                if (isset($this->_aValues[$sVarKey])
                    && is_object($this->_aValues[$sVarKey])
                    && isset($aContextData[1])) {
                    $sVarVal = $aContextData[1];
                    $oObject = $this->_aValues[$sVarKey];

                    // Try getVal
                    $sValCamelCase = StaticFilter::execute($sVarVal, 'Word\UnderscoreToCamelCase');
                    $sMethodGet = 'get' . $sValCamelCase;
                    $sMethodFetch = 'fetch' . $sValCamelCase;

                    if (method_exists($oObject, $sMethodGet)) {
                        $sVarData = $oObject->$sMethodGet();
                    } elseif (method_exists($oObject, $sMethodFetch)) {
                        $sVarData = $oObject->$sMethodFetch();
                    }
                }
                break;

            default:
                // String
                $sVarKey = $sTagContext;
                if (isset($this->_aValues[$sVarKey])) {
                    $sVarData = $this->_aValues[$sVarKey];
                }
        }

        // TODO: Default is escaping via View Function
        //$sVarData = $this->_oEscaper->__invoke($sVarData);

        return $sVarData;
    }

    /**
     * @return string
     */
    public function _getVarContent()
    {
        $sVarData = '';

        if (isset($this->_aValues['sContent'])) {
            $sVarData = $this->_aValues['sContent'];
        }

        return $sVarData;
    }

    /**
     * @return string
     */
    protected function _getPath()
    {
        if (is_string($this->_sPath)) {
            return $this->_sPath;
        }

        return $this->_oRequest->getRequestUri();
    }
}
