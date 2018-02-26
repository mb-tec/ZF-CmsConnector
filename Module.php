<?php

namespace MBtec\CmsConnector;

use Zend\Mvc;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\ServiceManager\ServiceManager;

/**
 * Class        Module
 * @package     MBtec\CmsConnector
 * @author      Matthias Büsing <info@mb-tec.eu>
 * @copyright   2018 Matthias Büsing
 * @license     http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link        http://mb-tec.eu
 */
class Module implements ConfigProviderInterface
{
    /**
     * @param Mvc\MvcEvent $e
     */
    public function onBootstrap(Mvc\MvcEvent $e)
    {
        $oEventManager = $e->getApplication()->getEventManager();
        $oEventManager->attach(Mvc\MvcEvent::EVENT_DISPATCH_ERROR, [$this, 'attachDispatchErrorHandler'], -999);
    }

    /**
     * @param Mvc\MvcEvent $e
     * @return mixed
     */
    public function attachDispatchErrorHandler(Mvc\MvcEvent $e)
    {
        if ($e->isError() && $e->getError() == Mvc\Application::ERROR_ROUTER_NO_MATCH) {
            $oCmsResponse = $e
                ->getApplication()
                ->getServiceManager()
                ->get('mbtec.cmsconnector.service')
                ->setIsErrorLookup(true)
                ->getResponse();

            if (is_object($oCmsResponse) && $oCmsResponse->isOk()) {
                $e->setError(false);

                return $oCmsResponse;
            }
        }
    }

    /**
     * @return mixed
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * @return array
     */
    public function getServiceConfig()
    {
        return [
            'factories' => [
                'mbtec.cmsconnector.service' => function (ServiceManager $oSm) {
                    return (new Service\CmsConnectorService())
                        ->setRequest($oSm->get('Request'))
                        ->setConfig($oSm->get('config')['mbtec']['cmsconnector'])
                        ->setViewHelperManager($oSm->get('ViewHelperManager'))
                        ->setLogger($oSm->get('mbtec.zf-log.service'))
                        ->setCache($oSm->get('cache'));
                },
            ],
        ];
    }
}
