<?php
declare(strict_types = 1);

namespace Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MatomoTracker implements MiddlewareInterface
{
    private $apiUrl;
    private $idSite;
    private $attribute = 'tracker';

    public function __construct(string $apiUrl, int $idSite)
    {
        $this->apiUrl = $apiUrl;
        $this->idSite = $idSite;
    }

    /**
     * Configure the attribute name used to save the tracker instance
     */
    public function attribute(string $attribute): self
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * Process a request and return a response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $tracker = new MatomoTracker\Tracker($request, $this->apiUrl, $this->idSite);

        $response = $handler->handle($request->withAttribute($this->attribute, $tracker));
        $tracker->createRequest()->send();

        return $response;
    }
}
