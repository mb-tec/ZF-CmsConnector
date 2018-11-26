<?php

namespace MBtec\CmsConnector;

use Zend\Mvc;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\ServiceManager\ServiceManager;
use Zend\Http\PhpEnvironment\Request as HttpRequest;

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
        $e->getApplication()->getEventManager()
            ->attach(Mvc\MvcEvent::EVENT_DISPATCH_ERROR, [$this, 'dispatchErrorHandler'], -999);
    }

    /**
     * @param Mvc\MvcEvent $e
     * @return mixed
     */
    public function dispatchErrorHandler(Mvc\MvcEvent $e)
    {
        $sm = $e->getApplication()->getServiceManager();
        $request = $sm->get('Request');

        if ($request instanceof HttpRequest
            && $e->isError()
            && $e->getError() == Mvc\Application::ERROR_ROUTER_NO_MATCH
        ) {
            $cmsResponse = $sm
                ->get('mbtec.cmsconnector.service')
                ->setIsErrorLookup(true)
                ->getResponse();

            if (is_object($cmsResponse) && $cmsResponse->isOk()) {
                $e->setError(false);

                return $cmsResponse;
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
                'mbtec.cmsconnector.service' => function (ServiceManager $sm) {
                    $request = $sm->get('Request');

                    if ($request instanceof HttpRequest) {
                        return (new Service\CmsConnectorService())
                            ->setRequest($sm->get('Request'))
                            ->setConfig($sm->get('config')['mbtec']['cmsconnector'])
                            ->setViewHelperManager($sm->get('ViewHelperManager'))
                            ->setLogger($sm->get('mbtec.zf-log.service'))
                            ->setCache($sm->get('cache'));
                    }

                    return null;
                },
            ],
        ];
    }
}
