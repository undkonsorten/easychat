<?php

namespace Undkonsorten\Easychat\Factories;

use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Bridge\Generic\ModelCatalog;
use Symfony\AI\Platform\Bridge\Generic\PlatformFactory as GenericPlatformFactory;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Builds Symfony AI platforms from a tx_easychat_configuration row, so retrieval
 * (ChatReaction) and indexing (Indexing\IndexEventListener) construct them identically.
 */
class AiPlatformFactory
{
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

        return GenericPlatformFactory::create($configuration['url'], $configuration['api_key'], HttpClient::create(), $modelCatalog);
    }

    public static function createEmbeddingsPlatform(array $configuration): PlatformInterface
    {
        $modelCatalog = new ModelCatalog([
            $configuration['vector_db_embeddings_model'] => [
                'class' => EmbeddingsModel::class,
                'capabilities' => [Capability::INPUT_MULTIPLE],
            ],
        ]);

        // Embeddings can be hosted on a different endpoint/provider than the chat
        // LLM; fall back to the chat LLM's url/api_key when not overridden.
        $url = $configuration['vector_db_embeddings_url'] ?: $configuration['url'];
        $apiKey = $configuration['vector_db_embeddings_api_key'] ?: $configuration['api_key'];

        return GenericPlatformFactory::create($url, $apiKey, HttpClient::create(), $modelCatalog);
    }
}
