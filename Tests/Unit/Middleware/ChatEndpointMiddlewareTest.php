<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use Undkonsorten\Easychat\Middleware\ChatEndpointMiddleware;

/**
 * The middleware runs for every frontend request. Anything that is not a chat request must be
 * passed on without touching the container, so none of the chat services are ever built.
 */
final class ChatEndpointMiddlewareTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function requestsThatAreNotChatRequests(): iterable
    {
        yield 'page view' => ['GET', '/', ''];
        yield 'form post to a page' => ['POST', '/contact', 'contact'];
        yield 'GET on the chat route' => ['GET', '/_easychat/chat', '_easychat/chat'];
        yield 'look-alike path' => ['POST', '/_easychat/chat/extra', '_easychat/chat/extra'];
        yield 'path suffix but a different route tail' => ['POST', '/foo/_easychat/chat', 'foo/_easychat/chat'];
        yield 'no routing attribute' => ['POST', '/_easychat/chat', null];
        yield 'former path without the underscore' => ['POST', '/easychat/chat', 'easychat/chat'];
    }

    #[DataProvider('requestsThatAreNotChatRequests')]
    public function testNonChatRequestsArePassedOnWithoutUsingTheContainer(string $method, string $path, ?string $tail): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException('The container must not be used for requests that are not chat requests.', 4227182226);
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        $request = new ServerRequest('https://example.com' . $path, $method);
        if ($tail !== null) {
            $site = new Site('main', 1, ['base' => 'https://example.com/']);
            $request = $request->withAttribute('routing', new SiteRouteResult($request->getUri(), $site, $site->getDefaultLanguage(), $tail));
        }
        $expected = new Response();
        $handler = new class ($expected) implements RequestHandlerInterface {
            public int $calls = 0;

            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;

                return $this->response;
            }
        };

        $response = (new ChatEndpointMiddleware($container))->process($request, $handler);

        self::assertSame($expected, $response);
        self::assertSame(1, $handler->calls);
    }
}
