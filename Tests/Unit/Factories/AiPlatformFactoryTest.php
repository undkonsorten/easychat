<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Factories;

use PHPUnit\Framework\TestCase;
use Undkonsorten\Easychat\Factories\AiPlatformFactory;

final class AiPlatformFactoryTest extends TestCase
{
    public function testResolveEmbeddingsUrlFallsBackToChatUrlWhenNotOverridden(): void
    {
        $configuration = ['url' => 'https://llm.example.com', 'vector_db_embeddings_url' => ''];

        self::assertSame('https://llm.example.com', AiPlatformFactory::resolveEmbeddingsUrl($configuration));
    }

    public function testResolveEmbeddingsUrlUsesOverrideWhenSet(): void
    {
        $configuration = ['url' => 'https://llm.example.com', 'vector_db_embeddings_url' => 'https://embeddings.example.com'];

        self::assertSame('https://embeddings.example.com', AiPlatformFactory::resolveEmbeddingsUrl($configuration));
    }

    public function testResolveEmbeddingsApiKeyFallsBackToChatApiKeyWhenNotOverridden(): void
    {
        $configuration = ['api_key' => 'chat-key', 'vector_db_embeddings_api_key' => ''];

        self::assertSame('chat-key', AiPlatformFactory::resolveEmbeddingsApiKey($configuration));
    }

    public function testResolveEmbeddingsApiKeyUsesOverrideWhenSet(): void
    {
        $configuration = ['api_key' => 'chat-key', 'vector_db_embeddings_api_key' => 'embeddings-key'];

        self::assertSame('embeddings-key', AiPlatformFactory::resolveEmbeddingsApiKey($configuration));
    }
}
