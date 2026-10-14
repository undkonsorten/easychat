<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Chat;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\NormalizedParams;
use Undkonsorten\Easychat\Chat\Exception\ChatRequestException;

/**
 * Handles one POST to the chat endpoint. Fetched from the container by ChatEndpointMiddleware
 * only when a request actually is a chat request, so none of its dependencies are built for
 * ordinary page requests.
 *
 * Everything the visitor may see is either a ChatRequestException's public message or the
 * generic error; provider, vector store and other internal errors only go to the log.
 */
class ChatRequestHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const GENERIC_ERROR = 'The assistant is currently unavailable.';

    public const MISSING_MARKER_ERROR = 'No consent given. Make sure there is a cookie named ' . ChatSessionCookie::MARKER_NAME . '.';

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ChatRequestValidator $validator,
        private readonly PluginResolver $pluginResolver,
        private readonly ChatSessionCookie $sessionCookie,
        private readonly ChatRateLimiter $rateLimiter,
        private readonly ChatService $chatService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$normalizedParams instanceof NormalizedParams) {
            $normalizedParams = NormalizedParams::createFromRequest($request);
        }
        $remoteIp = $normalizedParams->getRemoteAddress();
        $contentElementUid = 0;
        $configurationUid = 0;
        $sessionId = '';

        try {
            $this->validator->assertMethod($request);
            $this->validator->assertSameOrigin($request, $normalizedParams);
            $this->rateLimiter->checkIp($remoteIp);

            $body = $this->validator->decodeBody($request);
            $contentElementUid = (int)($body['contentElement'] ?? 0);
            $plugin = $this->pluginResolver->resolve($contentElementUid);
            $configurationUid = $plugin->configuration->uid;

            $marker = $request->getCookieParams()[ChatSessionCookie::MARKER_NAME] ?? '';
            if (!is_string($marker) || !ChatSessionCookie::isValidMarker($marker)) {
                throw new ChatRequestException(400, self::MISSING_MARKER_ERROR);
            }
            $markerHash = ChatSessionCookie::hashMarker($marker);

            [$sessionId, $isNewSession] = $this->sessionCookie->resolve($request, $markerHash);
            if ($isNewSession) {
                $this->rateLimiter->checkNewSession($remoteIp);
            }
            $this->rateLimiter->checkSession($sessionId);

            $userText = $this->validator->extractUserMessage($body, $plugin->configuration->maxInputLength);
            $answer = $this->chatService->respond($plugin->configuration, $sessionId, $markerHash, $userText);

            // "ai" is deep-chat's role for answers; any other value is a custom role without its styles.
            return $this->jsonResponse(['text' => $answer, 'role' => 'ai'])
                ->withAddedHeader(
                    'Set-Cookie',
                    ChatSessionCookie::build($sessionId, $plugin->cookieExpirationMinutes, $normalizedParams->isHttps()),
                );
        } catch (ChatRequestException $exception) {
            if ($exception->getStatusCode() >= 500) {
                $this->logger?->warning($exception->getMessage(), [
                    'contentElement' => $contentElementUid,
                    'configuration' => $configurationUid,
                ]);
            }
            $response = $this->jsonResponse(['error' => $exception->getPublicMessage()], $exception->getStatusCode());
            foreach ($exception->getHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        } catch (\Throwable $exception) {
            $this->logger?->error('Chat request failed', [
                'exception' => $exception,
                'contentElement' => $contentElementUid,
                'configuration' => $configurationUid,
                'session' => $sessionId,
            ]);

            return $this->jsonResponse(['error' => self::GENERIC_ERROR], 502);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $statusCode = 200): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streamFactory->createStream(json_encode($data, JSON_THROW_ON_ERROR)));
    }
}
