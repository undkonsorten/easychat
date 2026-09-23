<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Factories;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
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

    public function testCreateCompletionsPlatformRegistersTheConfiguredModel(): void
    {
        $configuration = ['url' => 'http://llm.invalid', 'api_key' => 'chat-key', 'model' => 'gpt-oss-120b'];

        $platform = AiPlatformFactory::createCompletionsPlatform($configuration);

        self::assertInstanceOf(PlatformInterface::class, $platform);
        $model = $platform->getModelCatalog()->getModel('gpt-oss-120b');
        self::assertInstanceOf(CompletionsModel::class, $model);
        // Similarity search is offered to the model as a tool.
        self::assertTrue($model->supports(Capability::TOOL_CALLING));
    }

    public function testCreateEmbeddingsPlatformRegistersTheEmbeddingsModel(): void
    {
        $configuration = [
            'url' => 'http://llm.invalid',
            'api_key' => 'chat-key',
            'vector_db_embeddings_url' => '',
            'vector_db_embeddings_api_key' => '',
            'vector_db_embeddings_model' => 'text-embedding-3-small',
        ];

        $platform = AiPlatformFactory::createEmbeddingsPlatform($configuration);

        self::assertInstanceOf(PlatformInterface::class, $platform);
        $model = $platform->getModelCatalog()->getModel('text-embedding-3-small');
        self::assertInstanceOf(EmbeddingsModel::class, $model);
        self::assertTrue($model->supports(Capability::INPUT_MULTIPLE));
    }
}
