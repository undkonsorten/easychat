<?php

namespace Undkonsorten\Easychat\Indexing;

use Undkonsorten\Easychat\Factories\StoreFactory;
use Undkonsorten\Easychat\Factories\VectorizerFactory;

/**
 * Builds the store and vectorizer for a tx_easychat_configuration row.
 */
class VectorTargetFactory
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function create(array $configuration): VectorTarget
    {
        $store = StoreFactory::create(
            $configuration['vector_db'],
            $configuration['vector_db_host'] . ':' . $configuration['vector_db_port'],
            $configuration['vector_db_api_key'],
            $configuration['vector_db_name'],
            (int)$configuration['vector_db_dimensions'],
        );
        $store->setup();

        return new VectorTarget(
            store: $store,
            vectorizer: VectorizerFactory::create($configuration),
        );
    }
}
