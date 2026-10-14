<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Chat\Exception;

/**
 * A chat request that must be answered with an error status. The public message is safe to
 * show to the visitor; the exception message may carry internal detail for the log.
 */
final class ChatRequestException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $publicMessage,
        private readonly array $headers = [],
        ?string $internalMessage = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($internalMessage ?? $publicMessage, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getPublicMessage(): string
    {
        return $this->publicMessage;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
