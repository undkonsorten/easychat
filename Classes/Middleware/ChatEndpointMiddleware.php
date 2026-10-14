<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use Undkonsorten\Easychat\Chat\ChatRequestHandler;

/**
 * The frontend chat endpoint: POST <language base>/_easychat/chat.
 *
 * This runs for every frontend request, so a request that is not a chat request must leave
 * after two string comparisons, and nothing chat-related may be constructed before that: the
 * request handler and its services are only fetched from the container once the route matched.
 * Matching on the route tail (what remains after the site language base) keeps language
 * prefixes and subdirectory installs working. The leading underscore is deliberate: TYPO3's slug
 * normaliser turns it into a dash, so no page slug, and thus no page POST, can ever collide with
 * this route.
 */
final readonly class ChatEndpointMiddleware implements MiddlewareInterface
{
    public const ROUTE_TAIL = '_easychat/chat';

    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'POST'
            || !str_ends_with($request->getUri()->getPath(), '/' . self::ROUTE_TAIL)
        ) {
            return $handler->handle($request);
        }

        $routing = $request->getAttribute('routing');
        if (!$routing instanceof SiteRouteResult || $routing->getTail() !== self::ROUTE_TAIL) {
            return $handler->handle($request);
        }

        /** @var ChatRequestHandler $chatRequestHandler */
        $chatRequestHandler = $this->container->get(ChatRequestHandler::class);

        return $chatRequestHandler->handle($request);
    }
}
