<?php
namespace TYPO3\Flow\Http;

/*
 * This file is part of the TYPO3.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Core\Bootstrap;
use TYPO3\Flow\Configuration\ConfigurationManager;
use TYPO3\Flow\Http\Component\ComponentContext;
use TYPO3\Flow\Package\Package;

/**
 * A request handler which can handle HTTP requests.
 *
 * @Flow\Scope("singleton")
 * @Flow\Proxy(false)
 */
class RequestHandler implements HttpRequestHandlerInterface
{
    /**
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var Component\ComponentChain
     */
    protected $baseComponentChain;

    /**
     * The "http" settings
     *
     * @var array
     */
    protected $settings;

    /**
     * Make exit() a closure so it can be manipulated during tests
     *
     * @var \Closure
     */
    public $exit;

    /**
     * @param Bootstrap $bootstrap
     */
    public function __construct(Bootstrap $bootstrap)
    {
        $this->bootstrap = $bootstrap;
        $this->exit = function () { exit(); };
    }

    /**
     * This request handler can handle any web request.
     *
     * @return boolean If the request is a web request, TRUE otherwise FALSE
     * @api
     */
    public function canHandleRequest()
    {
        return (PHP_SAPI !== 'cli');
    }

    /**
     * Returns the priority - how eager the handler is to actually handle the
     * request.
     *
     * @return integer The priority of the request handler.
     * @api
     */
    public function getPriority()
    {
        return 100;
    }

    /**
     * Handles a HTTP request
     *
     * @return void
     */
    public function handleRequest()
    {
        // Create the request very early so the Resource Management has a chance to grab it:
        $this->request = Request::createFromEnvironment();
        $this->response = new Response();

        $this->boot();
        $this->resolveDependencies();
        $this->addPoweredByHeader($this->response);
        if (isset($this->settings['http']['baseUri'])) {
            $this->request->setBaseUri(new Uri($this->settings['http']['baseUri']));
        }

        $componentContext = new ComponentContext($this->request, $this->response);
        $this->baseComponentChain->handle($componentContext);

        $this->response->send();

        $this->bootstrap->shutdown(Bootstrap::RUNLEVEL_RUNTIME);
        $this->exit->__invoke();
    }

    /**
     * Returns the currently handled HTTP request
     *
     * @return Request
     * @api
     */
    public function getHttpRequest()
    {
        return $this->request;
    }

    /**
     * Returns the HTTP response corresponding to the currently handled request
     *
     * @return Response
     * @api
     */
    public function getHttpResponse()
    {
        return $this->response;
    }

    /**
     * Boots up Flow to runtime
     *
     * @return void
     */
    protected function boot()
    {
        $sequence = $this->bootstrap->buildRuntimeSequence();
        $sequence->invoke($this->bootstrap);
    }

    /**
     * Resolves a few dependencies of this request handler which can't be resolved
     * automatically due to the early stage of the boot process this request handler
     * is invoked at.
     *
     * @return void
     */
    protected function resolveDependencies()
    {
        $objectManager = $this->bootstrap->getObjectManager();
        $this->baseComponentChain = $objectManager->get(\TYPO3\Flow\Http\Component\ComponentChain::class);

        $configurationManager = $objectManager->get(\TYPO3\Flow\Configuration\ConfigurationManager::class);
        $this->settings = $configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'TYPO3.Flow');
    }

    /**
     * Adds an HTTP header to the Response which indicates that the application is powered by Flow.
     *
     * @param Response $response
     * @return void
     */
    protected function addPoweredByHeader(Response $response)
    {
        if ($this->settings['http']['applicationToken'] === 'Off') {
            return;
        }

        /** @var Package $applicationPackage */
        /** @var Package $flowPackage */
        $flowPackage = $this->bootstrap->getEarlyInstance('TYPO3\Flow\Package\PackageManagerInterface')->getPackage('TYPO3.Flow');
        $applicationPackage = $this->bootstrap->getEarlyInstance('TYPO3\Flow\Package\PackageManagerInterface')->getPackage($this->settings['core']['applicationPackageKey']);
        $applicationIsFlow = ($this->settings['core']['applicationPackageKey'] === 'TYPO3.Flow');

        switch ($this->settings['http']['applicationToken']) {
            case 'ApplicationName':
                if ($applicationIsFlow) {
                    $response->getHeaders()->set('X-Powered-By', 'Flow');
                } else {
                    $response->getHeaders()->set('X-Powered-By', 'Flow ' . $this->settings['core']['applicationName']);
                }
            break;
            case 'MajorVersion':
                preg_match('/^(\d+)/', $flowPackage->getInstalledVersion(), $flowVersionMatches);
                $flowVersion = isset($flowVersionMatches[1]) ? $flowVersionMatches[1] : '';
                preg_match('/^(\d+)/', $applicationPackage->getInstalledVersion(), $applicationVersionMatches);
                $applicationVersion = isset($applicationVersionMatches[1]) ? $applicationVersionMatches[1] : '';
                if ($applicationIsFlow) {
                    $response->getHeaders()->set('X-Powered-By', 'Flow/' . $flowVersion ?: 'dev');
                } else {
                    $response->getHeaders()->set('X-Powered-By', 'Flow/' . ($flowVersion ?: 'dev') . ' ' . $this->settings['core']['applicationName'] . '/' . ($applicationVersion ?: 'dev'));
                }
            break;
            case 'MinorVersion':
                preg_match('/^(\d+\.\d+)/', $flowPackage->getInstalledVersion(), $flowVersionMatches);
                $flowVersion = isset($flowVersionMatches[1]) ? $flowVersionMatches[1] : '';
                preg_match('/^(\d+\.\d+)/', $applicationPackage->getInstalledVersion(), $applicationVersionMatches);
                $applicationVersion = isset($applicationVersionMatches[1]) ? $applicationVersionMatches[1] : '';
                if ($applicationIsFlow) {
                    $response->getHeaders()->set('X-Powered-By', 'Flow/' . $flowVersion ?: 'dev');
                } else {
                    $response->getHeaders()->set('X-Powered-By', 'Flow/' . ($flowVersion ?: 'dev') . ' ' . $this->settings['core']['applicationName'] . '/' . ($applicationVersion ?: 'dev'));
                }
            break;
        }
    }
}
