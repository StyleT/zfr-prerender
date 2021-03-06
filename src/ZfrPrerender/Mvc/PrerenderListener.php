<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace ZfrPrerender\Mvc;

use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\Http\Client as HttpClient;
use Zend\Http\Request as HttpRequest;
use Zend\Http\Response as HttpResponse;
use Zend\Mvc\MvcEvent;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use ZfrPrerender\Options\ModuleOptions;

/**
 * This class registers a listener very early in the MVC process (in the MvcEvent::EVENT_BOOTSTRAP) with a
 * very high priority. It first checks if it must prerender the page (according to the extensions, whitelist...). If
 * so, it performs a GET request to the service, and returns the HTML
 *
 * @author Michaël Gallego
 * @licence MIT
 */
class PrerenderListener extends AbstractListenerAggregate implements EventManagerAwareInterface
{
    /**
     * @var ModuleOptions
     */
    protected $moduleOptions;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var EventManagerInterface
     */
    protected $eventManager;

    /**
     * @param ModuleOptions $options
     */
    public function __construct(ModuleOptions $options)
    {
        $this->moduleOptions = $options;
    }

    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events)
    {
        $this->listeners[] = $events->attach(MvcEvent::EVENT_ROUTE, [$this, 'prerenderPage'], 1000);
    }

    /**
     * Set the HTTP client used to perform the GET request
     *
     * @param  HttpClient $httpClient
     * @return void
     */
    public function setHttpClient(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Get the HTTP client used to perform the GET request
     *
     * @return HttpClient
     */
    public function getHttpClient()
    {
        if (null === $this->httpClient) {
            $this->httpClient = new HttpClient();
            if ($httpClientOptions = $this->moduleOptions->getHttpClientOptions()) {
                $this->httpClient->setOptions($httpClientOptions);
            }
        }

        return $this->httpClient;
    }

    /**
     * Pre-render the page
     *
     * @param  MvcEvent $event
     * @return void|ResponseInterface
     */
    public function prerenderPage(MvcEvent $event)
    {
        $originalRequest = $event->getRequest();

        if (!$this->shouldPrerenderPage($originalRequest)) {
            return;
        }

        $event->stopPropagation(true);
        $eventManager = $this->getEventManager();

        // Trigger a pre-event (for creating a response from cache, for instance)
        $responses = $eventManager->trigger(PrerenderEvent::EVENT_PRERENDER_PRE, new PrerenderEvent($originalRequest));

        if ($responses->last() instanceof HttpResponse) {
            return $responses->last();
        }

        // Make the actual request to Prerender service
        $client    = $this->getHttpClient();
        $uri       = rtrim($this->moduleOptions->getPrerenderUrl(), '/') . '/' . $originalRequest->getUriString();
        $userAgent = $originalRequest->getHeaders()->get('User-Agent')->getFieldValue();

        $client->setUri($uri)
               ->setMethod(HttpRequest::METHOD_GET);

        $request = $client->getRequest();
        $request->getHeaders()->addHeaderLine('User-Agent', $userAgent)
                              ->addHeaderLine('Accept-Encoding', 'gzip');

        if ($prerenderToken = $this->moduleOptions->getPrerenderToken()) {
            $request->getHeaders()->addHeaderLine('X-Prerender-Token', $prerenderToken);
        }

        $response = $client->send($request);

        // Trigger a post-event (for putting in cache the response, for instance)
        $prerenderEvent = new PrerenderEvent($request, $response);
        $eventManager->trigger(PrerenderEvent::EVENT_PRERENDER_POST, $prerenderEvent);

        return $prerenderEvent->getResponse();
    }

    /**
     * Is this request should be a prerender request?
     *
     * @param RequestInterface $request
     * @return bool
     */
    public function shouldPrerenderPage(RequestInterface $request)
    {
        if (!$request instanceof HttpRequest) {
            return false;
        }

        // First, return false if User Agent is not a bot
        if (!$this->isCrawler($request)) {
            return false;
        }

        $uri = $request->getUriString();

        // Then, return false if URI string contains an ignored extension
        foreach ($this->moduleOptions->getIgnoredExtensions() as $ignoredExtension) {
            if (strpos($uri, $ignoredExtension) !== false) {
                return false;
            }
        }

        // Then, return true if it is whitelisted (only if whitelist contains data)
        $whitelistUrls = $this->moduleOptions->getWhitelistUrls();

        if (!empty($whitelistUrls) && !$this->isWhitelisted($uri, $whitelistUrls)) {
            return false;
        }

        // Finally, return false if it is blacklisted (or the referer)
        $referer       = $request->getHeader('Referer') ? $request->getHeader('Referer')->getFieldValue() : null;
        $blacklistUrls = $this->moduleOptions->getBlacklistUrls();

        if (!empty($blacklistUrls) && $this->isBlacklisted($uri, $referer, $blacklistUrls)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the request is made from a crawler
     *
     * To detect if a request comes from a bot, we have two strategies:
     *      1. We first check if the "_escaped_fragment_" query param is defined. This is only
     *         implemented by some search engines (Google, Yahoo and Bing among others)
     *      2. If not, we use the User-Agent string
     *
     * @param  HttpRequest $request
     * @return bool
     */
    protected function isCrawler(HttpRequest $request)
    {
        if (null !== $request->getQuery('_escaped_fragment_')) {
            return true;
        }

        $userAgent = strtolower($request->getHeader('User-Agent')->getFieldValue());

        foreach ($this->moduleOptions->getCrawlerUserAgents() as $crawlerUserAgent) {
            if (strpos($userAgent, strtolower($crawlerUserAgent)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the request is whitelisted
     *
     * @param  string $uri
     * @param  array $whitelistUrls
     * @return bool
     */
    protected function isWhitelisted($uri, array $whitelistUrls)
    {
        foreach ($whitelistUrls as $whitelistUrl) {
            $match = preg_match('`' . $whitelistUrl . '`i', $uri);

            if ($match > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the request is blacklisted
     *
     * @param  string $uri
     * @param  string $referer
     * @param  array $blacklistUrls
     * @return bool
     */
    protected function isBlacklisted($uri, $referer, array $blacklistUrls)
    {
        foreach ($blacklistUrls as $blacklistUrl) {
            $pattern = '`' . $blacklistUrl . '`i';
            $match   = preg_match($pattern, $uri) + preg_match($pattern, $referer);

            if ($match > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function setEventManager(EventManagerInterface $eventManager)
    {
        $eventManager->setIdentifiers([__CLASS__, get_class($this)]);
        $this->eventManager = $eventManager;
    }

    /**
     * {@inheritDoc}
     */
    public function getEventManager()
    {
        if (null === $this->eventManager) {
            $this->setEventManager(new EventManager());
        }

        return $this->eventManager;
    }
}
