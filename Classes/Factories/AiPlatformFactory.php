<?php

namespace Undkonsorten\Easychat\Factories;

use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericPlatformFactory;
use Symfony\AI\Platform\Bridge\Generic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Builds Symfony AI platforms from a tx_easychat_configuration row, so the chat (AgentFactory)
 * and indexing (Indexing\IndexEventListener) construct them identically.
 */
class AiPlatformFactory
{
    /**
     * Seconds to wait for the provider to start answering, and for the whole response. A chat
     * request must never hang on an unreachable or stalled provider.
     */
    public const HTTP_CLIENT_OPTIONS = ['timeout' => 15, 'max_duration' => 90];

    public static function createCompletionsPlatform(array $configuration): PlatformInterface
    {
        $modelCatalog = new ModelCatalog([
            $configuration['model'] => [
                'class' => CompletionsModel::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::TOOL_CALLING,
                ],
            ],
        ]);

        return GenericPlatformFactory::createPlatform($configuration['url'], $configuration['api_key'], HttpClient::create(self::HTTP_CLIENT_OPTIONS), $modelCatalog);
    }

    public static function createEmbeddingsPlatform(array $configuration): PlatformInterface
    {
        $modelCatalog = new ModelCatalog([
            $configuration['vector_db_embeddings_model'] => [
                'class' => EmbeddingsModel::class,
                'capabilities' => [Capability::INPUT_MULTIPLE],
            ],
        ]);

        return GenericPlatformFactory::createPlatform(
            self::resolveEmbeddingsUrl($configuration),
            self::resolveEmbeddingsApiKey($configuration),
            HttpClient::create(self::HTTP_CLIENT_OPTIONS),
            $modelCatalog,
        );
    }

    /**
     * Embeddings can be hosted on a different endpoint/provider than the chat LLM;
     * fall back to the chat LLM's url/api_key when not overridden.
     */
    public static function resolveEmbeddingsUrl(array $configuration): string
    {
        return $configuration['vector_db_embeddings_url'] ?: $configuration['url'];
    }

    public static function resolveEmbeddingsApiKey(array $configuration): string
    {
        return $configuration['vector_db_embeddings_api_key'] ?: $configuration['api_key'];
    }
}
