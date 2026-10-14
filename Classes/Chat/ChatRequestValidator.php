<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Chat;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use Undkonsorten\Easychat\Chat\Exception\ChatRequestException;

/**
 * Checks everything about a chat request that does not need the database: method, origin,
 * content type, body size, JSON shape and the user message itself. Every rejection is a
 * ChatRequestException with a status code and a message that is safe to show.
 */
final class ChatRequestValidator
{
    public const MAX_BODY_BYTES = 65536;

    public const JSON_DEPTH = 16;

    public function assertMethod(ServerRequestInterface $request): void
    {
        if ($request->getMethod() !== 'POST') {
            throw new ChatRequestException(405, 'Method not allowed.', ['Allow' => 'POST']);
        }
    }

    /**
     * Same-origin check on Origin, falling back to Referer. Both are absent for some privacy
     * proxies, so a missing header is tolerated: the SameSite session cookie already keeps
     * cross-site requests from using an existing conversation.
     */
    public function assertSameOrigin(ServerRequestInterface $request, NormalizedParams $normalizedParams): void
    {
        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '' || $origin === 'null') {
            $origin = trim($request->getHeaderLine('Referer'));
        }
        if ($origin === '') {
            return;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        if (!is_string($originHost) || strcasecmp($originHost, $normalizedParams->getRequestHostOnly()) !== 0) {
            throw new ChatRequestException(403, 'Cross-origin requests are not allowed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeBody(ServerRequestInterface $request): array
    {
        $contentType = strtolower(trim($request->getHeaderLine('Content-Type')));
        if (!str_starts_with($contentType, 'application/json')) {
            throw new ChatRequestException(415, 'Requests must be sent as application/json.');
        }

        $contentLength = $request->getHeaderLine('Content-Length');
        if ($contentLength !== '' && (int)$contentLength > self::MAX_BODY_BYTES) {
            throw new ChatRequestException(413, 'The request is too large.');
        }

        $raw = $this->readBounded($request);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new ChatRequestException(413, 'The request is too large.');
        }

        try {
            $body = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ChatRequestException(400, 'The request body is not valid JSON.', [], null, $exception);
        }
        if (!is_array($body)) {
            throw new ChatRequestException(400, 'The request body must be a JSON object.');
        }

        return $body;
    }

    /**
     * deep-chat posts {"messages": [{"role": "user", "text": "..."}]}. Only the last message is
     * the new question; the conversation itself comes from the server-side session.
     *
     * @param array<string, mixed> $body
     */
    public function extractUserMessage(array $body, int $maxInputLength): string
    {
        $messages = $body['messages'] ?? null;
        if (!is_array($messages) || $messages === [] || !array_is_list($messages)) {
            throw new ChatRequestException(400, 'No messages given.');
        }
        $last = end($messages);
        if (!is_array($last) || !isset($last['text']) || !is_string($last['text'])) {
            throw new ChatRequestException(400, 'The last message must contain a text.');
        }
        $text = trim($last['text']);
        if ($text === '') {
            throw new ChatRequestException(400, 'The message is empty.');
        }
        if (mb_strlen($text) > $maxInputLength) {
            throw new ChatRequestException(400, sprintf('The message is too long. Please keep it under %d characters.', $maxInputLength));
        }

        return $text;
    }

    /**
     * Reads at most one byte more than allowed, so an oversized body without Content-Length is
     * rejected without buffering all of it.
     */
    private function readBounded(ServerRequestInterface $request): string
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = '';
        while (!$stream->eof() && strlen($raw) <= self::MAX_BODY_BYTES) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $raw .= $chunk;
        }

        return $raw;
    }
}
