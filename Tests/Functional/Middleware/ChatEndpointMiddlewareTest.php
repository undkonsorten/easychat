<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\Chat\ChatRateLimiter;
use Undkonsorten\Easychat\Chat\ChatRequestHandler;
use Undkonsorten\Easychat\Chat\ChatRequestValidator;
use Undkonsorten\Easychat\Chat\ChatService;
use Undkonsorten\Easychat\Chat\ChatSessionCookie;
use Undkonsorten\Easychat\Chat\PluginResolver;
use Undkonsorten\Easychat\Domain\Repository\SessionRepository;
use Undkonsorten\Easychat\Middleware\ChatEndpointMiddleware;
use Undkonsorten\Easychat\Tests\Fixtures\Chat\RecordingAgentFactory;

/**
 * The whole chat request path end to end, with the model replaced by RecordingAgentFactory:
 * routing, validation, chatbot resolution, server-issued session cookie, rate limits and
 * error masking.
 */
final class ChatEndpointMiddlewareTest extends FunctionalTestCase
{
    private const MARKER = '0f9c8a7e-1234-4bcd-9ef0-123456789abc';
    protected array $coreExtensionsToLoad = ['install'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    private RecordingAgentFactory $agentFactory;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    private ChatEndpointMiddleware $middleware;

    /** @var array<string, mixed> */
    private array $rateLimits = ['rateLimitPerIpPerMinute' => 0, 'rateLimitPerSessionPerHour' => 0, 'rateLimitNewSessionsPerIpPerHour' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ChatPlugins.csv');
        $this->agentFactory = new RecordingAgentFactory();
        $this->middleware = $this->buildMiddleware();
    }

    public function testRequestsForOtherRoutesArePassedOn(): void
    {
        $next = new Response();
        $response = $this->middleware->process($this->request(['messages' => []], path: '/', tail: '', method: 'GET'), $this->nextHandler($next));

        self::assertSame($next, $response);
    }

    public function testAChatTurnAnswersAndIssuesAnHttpOnlySessionCookie(): void
    {
        $response = $this->send(['messages' => [['role' => 'user', 'text' => 'Hello']], 'contentElement' => 10]);

        self::assertSame(200, $response->getStatusCode(), $this->describeLog());
        self::assertSame(['text' => 'answer-1', 'role' => 'ai'], $this->json($response));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertMatchesRegularExpression('/^easychat_session=[0-9a-f]{64};/', $cookie);
        self::assertStringContainsString('httponly', $cookie);
        self::assertStringContainsString('samesite=strict', $cookie);
        self::assertStringContainsString('secure', $cookie);
        self::assertStringContainsString('Max-Age=3600', $cookie, 'The content element asked for 60 minutes.');

        $rows = $this->get(SessionRepository::class)->findAll()->toArray();
        self::assertCount(1, $rows);
        self::assertSame($this->sessionIdFrom($response), $rows[0]->getSessionId());
        self::assertSame(1, $rows[0]->getConfiguration());
        self::assertSame(sha1(self::MARKER), $rows[0]->getClientMarker());
        self::assertSame(['max_tokens' => 77], $this->agentFactory->options[0]);
    }

    public function testTheSameSessionCookieContinuesTheConversation(): void
    {
        $first = $this->send($this->message('one'));
        $sessionId = $this->sessionIdFrom($first);

        $second = $this->send($this->message('two'), [ChatSessionCookie::NAME => $sessionId]);

        self::assertSame($sessionId, $this->sessionIdFrom($second), 'The cookie is re-sent to refresh its lifetime, with the same id.');
        self::assertCount(1, $this->get(SessionRepository::class)->findAll());
        self::assertSame(4, count($this->agentFactory->inputs[1]), 'system, one, answer-1, two');
    }

    public function testAChangedConsentMarkerStartsANewConversation(): void
    {
        $first = $this->send($this->message('one'));
        $sessionId = $this->sessionIdFrom($first);

        $second = $this->send($this->message('two'), [ChatSessionCookie::NAME => $sessionId, ChatSessionCookie::MARKER_NAME => 'renewed-consent']);

        self::assertNotSame($sessionId, $this->sessionIdFrom($second));
        self::assertCount(2, $this->get(SessionRepository::class)->findAll());
        self::assertSame(2, count($this->agentFactory->inputs[1]), 'Only system message and the new question: the old conversation is not continued.');
    }

    public function testOneSessionHoldsOneConversationPerChatbot(): void
    {
        $sessionId = $this->sessionIdFrom($this->send($this->message('to A', 10)));

        $toB = $this->send($this->message('to B', 17), [ChatSessionCookie::NAME => $sessionId]);
        self::assertSame($sessionId, $this->sessionIdFrom($toB), 'Switching chatbots keeps the session.');
        self::assertSame(2, count($this->agentFactory->inputs[1]), 'Chatbot B starts fresh.');

        $this->send($this->message('to A again', 10), [ChatSessionCookie::NAME => $sessionId]);
        self::assertSame(4, count($this->agentFactory->inputs[2]), 'Chatbot A continues: system, to A, answer-1, to A again.');

        $rows = $this->get(SessionRepository::class)->findAll()->toArray();
        self::assertCount(2, $rows);
        self::assertSame([1, 2], array_map(static fn($row) => $row->getConfiguration(), $rows));
    }

    public function testUnknownOrForgedSessionCookiesGetAFreshSession(): void
    {
        $forged = $this->send($this->message('hi'), [ChatSessionCookie::NAME => str_repeat('ab', 32)]);
        self::assertSame(200, $forged->getStatusCode());
        self::assertNotSame(str_repeat('ab', 32), $this->sessionIdFrom($forged));

        $legacy = $this->send($this->message('hi'), [ChatSessionCookie::NAME => self::MARKER]);
        self::assertSame(200, $legacy->getStatusCode());
        self::assertTrue(ChatSessionCookie::isValidId($this->sessionIdFrom($legacy)));
    }

    public function testWithoutConsentMarkerThereIsNoChat(): void
    {
        $response = $this->send($this->message('hi'), [ChatSessionCookie::MARKER_NAME => null]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => ChatRequestHandler::MISSING_MARKER_ERROR], $this->json($response));
        self::assertSame([], $this->agentFactory->inputs);
    }

    public function testInvisibleChatbotsAre404(): void
    {
        foreach ([11, 12, 13, 18, 999] as $contentElement) {
            $response = $this->send($this->message('hi', $contentElement));
            self::assertSame(404, $response->getStatusCode(), 'Content element ' . $contentElement);
            self::assertSame(['error' => 'Unknown chatbot.'], $this->json($response));
        }
        self::assertSame([], $this->agentFactory->inputs);
    }

    public function testMisconfiguredChatbotsAre503WithAGenericMessageAndAWarning(): void
    {
        foreach ([14, 15, 16] as $contentElement) {
            $response = $this->send($this->message('hi', $contentElement));
            self::assertSame(503, $response->getStatusCode(), 'Content element ' . $contentElement);
            self::assertSame(['error' => ChatRequestHandler::GENERIC_ERROR], $this->json($response));
        }
        self::assertCount(3, $this->logRecords);
        self::assertSame('warning', $this->logRecords[0]['level']);
        self::assertStringContainsString('configuration 5', $this->logRecords[0]['message']);
    }

    public function testProviderErrorsAreLoggedButNotShown(): void
    {
        $this->agentFactory->failure = new \RuntimeException('Connection to http://qdrant.internal:6333 refused');

        $response = $this->send($this->message('hi'));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('{"error":"The assistant is currently unavailable."}', (string)$response->getBody());
        self::assertStringNotContainsString('qdrant', (string)$response->getBody());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertCount(1, $this->logRecords);
        self::assertSame('error', $this->logRecords[0]['level']);
        self::assertSame($this->agentFactory->failure, $this->logRecords[0]['context']['exception']);
        self::assertSame(10, $this->logRecords[0]['context']['contentElement']);
        self::assertSame(1, $this->logRecords[0]['context']['configuration']);
        self::assertArrayNotHasKey('ip', $this->logRecords[0]['context']);
    }

    public function testCrossOriginAndWrongMethodAreRejected(): void
    {
        $response = $this->send($this->message('hi'), headers: ['Origin' => 'https://evil.example']);
        self::assertSame(403, $response->getStatusCode());

        $response = $this->send($this->message('hi'), headers: ['Content-Type' => 'text/plain']);
        self::assertSame(415, $response->getStatusCode());

        self::assertSame([], $this->agentFactory->inputs);
    }

    public function testTooLongMessagesAreRejectedBeforeTheModelIsAsked(): void
    {
        $response = $this->send($this->message(str_repeat('x', 51)));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('50', $this->json($response)['error']);
        self::assertSame([], $this->agentFactory->inputs);
        self::assertCount(0, $this->get(SessionRepository::class)->findAll(), 'No session is stored for a rejected message.');
    }

    public function testTooManyRequestsFromOneAddressAre429(): void
    {
        $this->rateLimits = ['rateLimitPerIpPerMinute' => 2, 'rateLimitPerSessionPerHour' => 0, 'rateLimitNewSessionsPerIpPerHour' => 0];
        $this->middleware = $this->buildMiddleware();

        self::assertSame(200, $this->send($this->message('one'))->getStatusCode());
        self::assertSame(200, $this->send($this->message('two'))->getStatusCode());
        $response = $this->send($this->message('three'));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(['error' => ChatRateLimiter::PUBLIC_MESSAGE], $this->json($response));
        self::assertGreaterThanOrEqual(1, (int)$response->getHeaderLine('Retry-After'));
        self::assertCount(2, $this->agentFactory->inputs);

        self::assertSame(200, $this->send($this->message('other address'), serverParams: ['REMOTE_ADDR' => '198.51.100.7'])->getStatusCode());
    }

    public function testStartingOverWithoutACookieIsLimitedPerAddress(): void
    {
        $this->rateLimits = ['rateLimitPerIpPerMinute' => 0, 'rateLimitPerSessionPerHour' => 0, 'rateLimitNewSessionsPerIpPerHour' => 1];
        $this->middleware = $this->buildMiddleware();

        $first = $this->send($this->message('one'));
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $this->send($this->message('two'), [ChatSessionCookie::NAME => $this->sessionIdFrom($first)])->getStatusCode(), 'Continuing the session is no new session.');
        self::assertSame(429, $this->send($this->message('three'))->getStatusCode(), 'Dropping the cookie to start over is.');
    }

    public function testTooManyMessagesInOneSessionAre429(): void
    {
        $this->rateLimits = ['rateLimitPerIpPerMinute' => 0, 'rateLimitPerSessionPerHour' => 1, 'rateLimitNewSessionsPerIpPerHour' => 0];
        $this->middleware = $this->buildMiddleware();

        $first = $this->send($this->message('one'));
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $this->send($this->message('two'), [ChatSessionCookie::NAME => $this->sessionIdFrom($first)])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string|null> $cookies
     * @param array<string, string> $headers
     * @param array<string, string> $serverParams
     */
    private function send(array $body, array $cookies = [], array $headers = [], array $serverParams = []): ResponseInterface
    {
        return $this->middleware->process($this->request($body, $cookies, $headers, $serverParams), $this->nextHandler(new Response()));
    }

    /**
     * @return array<string, mixed>
     */
    private function message(string $text, int $contentElement = 10): array
    {
        return ['messages' => [['role' => 'user', 'text' => $text]], 'contentElement' => $contentElement];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string|null> $cookies
     * @param array<string, string> $headers
     * @param array<string, string> $serverParams
     */
    private function request(array $body, array $cookies = [], array $headers = [], array $serverParams = [], string $path = '/_easychat/chat', string $tail = '_easychat/chat', string $method = 'POST'): ServerRequestInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();
        $serverParams = array_replace([
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_URI' => $path,
            'REQUEST_METHOD' => $method,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
            'REMOTE_ADDR' => '203.0.113.5',
        ], $serverParams);
        $request = new ServerRequest(
            'https://example.com' . $path,
            $method,
            $stream,
            array_replace(['Content-Type' => 'application/json', 'Origin' => 'https://example.com'], $headers),
            $serverParams,
        );
        $cookies = array_filter(
            array_replace([ChatSessionCookie::MARKER_NAME => self::MARKER], $cookies),
            static fn(?string $value): bool => $value !== null,
        );
        $site = new Site('main', 1, ['base' => 'https://example.com/']);

        return $request
            ->withCookieParams($cookies)
            ->withAttribute('routing', new SiteRouteResult($request->getUri(), $site, $site->getDefaultLanguage(), $tail))
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request, []));
    }

    private function buildMiddleware(): ChatEndpointMiddleware
    {
        $repository = $this->get(SessionRepository::class);
        $handler = new ChatRequestHandler(
            new ResponseFactory(),
            new StreamFactory(),
            new ChatRequestValidator(),
            $this->get(PluginResolver::class),
            new ChatSessionCookie($repository),
            new ChatRateLimiter($this->get(ExtensionConfiguration::class), new InMemoryStorage(), $this->rateLimits),
            new ChatService($repository, $this->agentFactory),
        );
        $test = $this;
        $handler->setLogger(new class ($test) extends AbstractLogger {
            public function __construct(private readonly ChatEndpointMiddlewareTest $test) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->test->record((string)$level, (string)$message, $context);
            }
        });
        $container = new class ($handler) implements ContainerInterface {
            public function __construct(private readonly ChatRequestHandler $handler) {}

            public function get(string $id): mixed
            {
                return $this->handler;
            }

            public function has(string $id): bool
            {
                return $id === ChatRequestHandler::class;
            }
        };

        return new ChatEndpointMiddleware($container);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function record(string $level, string $message, array $context): void
    {
        $this->logRecords[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    private function describeLog(): string
    {
        $lines = [];
        foreach ($this->logRecords as $record) {
            $exception = $record['context']['exception'] ?? null;
            $lines[] = $record['level'] . ': ' . $record['message'] . ($exception instanceof \Throwable ? ' - ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine() : '');
        }

        return $lines === [] ? 'Nothing was logged.' : implode("\n", $lines);
    }

    private function nextHandler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function sessionIdFrom(ResponseInterface $response): string
    {
        self::assertSame(1, preg_match('/^easychat_session=([0-9a-f]{64});/', $response->getHeaderLine('Set-Cookie'), $matches), 'A session cookie is expected: ' . $response->getHeaderLine('Set-Cookie'));

        return $matches[1];
    }
}
