<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Chat;

/**
 * The parts of a tx_easychat_configuration row the chat request path needs, with the cost
 * limits already cast and defaulted. The raw row stays available for the factories that build
 * the platform, vector store and vectorizer from it.
 */
final readonly class ChatConfiguration
{
    public const DEFAULT_MAX_INPUT_LENGTH = 2000;

    public const DEFAULT_MAX_TOKENS = 1024;

    public const DEFAULT_HISTORY_WINDOW = 20;

    /**
     * @param array<string, mixed> $row
     */
    public function __construct(
        public int $uid,
        public string $model,
        public string $systemMessage,
        public int $maxInputLength,
        public int $maxTokens,
        public int $historyWindow,
        public array $row,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)($row['uid'] ?? 0),
            (string)($row['model'] ?? ''),
            (string)($row['system_message'] ?? ''),
            self::positiveIntOrDefault($row['max_input_length'] ?? null, self::DEFAULT_MAX_INPUT_LENGTH),
            self::positiveIntOrDefault($row['max_tokens'] ?? null, self::DEFAULT_MAX_TOKENS),
            self::nonNegativeIntOrDefault($row['history_window'] ?? null, self::DEFAULT_HISTORY_WINDOW),
            $row,
        );
    }

    public function hasVectorStore(): bool
    {
        $vectorDb = (string)($this->row['vector_db'] ?? '');

        return $vectorDb !== '' && $vectorDb !== 'none';
    }

    private static function positiveIntOrDefault(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $value = (int)$value;

        return $value > 0 ? $value : $default;
    }

    private static function nonNegativeIntOrDefault(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $value = (int)$value;

        return $value >= 0 ? $value : $default;
    }
}
