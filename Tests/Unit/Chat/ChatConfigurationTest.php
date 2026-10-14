<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Chat;

use PHPUnit\Framework\TestCase;
use Undkonsorten\Easychat\Chat\ChatConfiguration;

final class ChatConfigurationTest extends TestCase
{
    public function testAppliesDefaultsWhenTheLimitColumnsAreMissing(): void
    {
        $configuration = ChatConfiguration::fromRow(['uid' => '7', 'model' => 'gpt', 'system_message' => 'be nice']);

        self::assertSame(7, $configuration->uid);
        self::assertSame('gpt', $configuration->model);
        self::assertSame('be nice', $configuration->systemMessage);
        self::assertSame(ChatConfiguration::DEFAULT_MAX_INPUT_LENGTH, $configuration->maxInputLength);
        self::assertSame(ChatConfiguration::DEFAULT_MAX_TOKENS, $configuration->maxTokens);
        self::assertSame(ChatConfiguration::DEFAULT_HISTORY_WINDOW, $configuration->historyWindow);
        self::assertFalse($configuration->hasVectorStore());
    }

    public function testCastsTheLimitColumns(): void
    {
        $configuration = ChatConfiguration::fromRow([
            'uid' => 1,
            'max_input_length' => '500',
            'max_tokens' => '256',
            'history_window' => '0',
            'vector_db' => 'qdrant',
        ]);

        self::assertSame(500, $configuration->maxInputLength);
        self::assertSame(256, $configuration->maxTokens);
        self::assertSame(0, $configuration->historyWindow, 'A history window of 0 is a valid choice, not a missing value.');
        self::assertTrue($configuration->hasVectorStore());
    }

    public function testInvalidValuesFallBackToTheDefaults(): void
    {
        $configuration = ChatConfiguration::fromRow(['uid' => 1, 'max_input_length' => '0', 'max_tokens' => '-5', 'history_window' => '-1', 'vector_db' => 'none']);

        self::assertSame(ChatConfiguration::DEFAULT_MAX_INPUT_LENGTH, $configuration->maxInputLength);
        self::assertSame(ChatConfiguration::DEFAULT_MAX_TOKENS, $configuration->maxTokens);
        self::assertSame(ChatConfiguration::DEFAULT_HISTORY_WINDOW, $configuration->historyWindow);
        self::assertFalse($configuration->hasVectorStore());
    }
}
