<?php

namespace Undkonsorten\Easychat\Indexing;

use Symfony\Component\HttpClient\HttpClient;
use Undkonsorten\Easychat\Factories\StoreFactory;
use Undkonsorten\Easychat\Factories\VectorizerFactory;

/**
 * Builds the store, vectorizer and point remover for a tx_easychat_configuration row.
 */
class VectorTargetFactory
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function create(array $configuration): VectorTarget
    {
        $url = $configuration['vector_db_host'] . ':' . $configuration['vector_db_port'];
        $store = StoreFactory::create(
            $configuration['vector_db'],
            $url,
            $configuration['vector_db_api_key'],
            $configuration['vector_db_name'],
            (int)$configuration['vector_db_dimensions'],
        );
        $store->setup();

        return new VectorTarget(
            store: $store,
            vectorizer: VectorizerFactory::create($configuration),
            remover: $this->createRemover($configuration, $url),
        );
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function createRemover(array $configuration, string $url): PointRemoverInterface
    {
        return match ($configuration['vector_db']) {
            'qdrant' => new QdrantPointRemover(
                HttpClient::create(),
                $url,
                $configuration['vector_db_api_key'],
                $configuration['vector_db_name'],
            ),
            default => throw new \Exception(sprintf('Vector store "%s" is not supported.', $configuration['vector_db'])),
        };
    }
}
