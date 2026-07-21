<?php

namespace Undkonsorten\Easychat\Factories;

use Symfony\AI\Store\Document\Vectorizer;

class VectorizerFactory
{
    public static function create(array $configuration): Vectorizer
    {
        return new Vectorizer(
            AiPlatformFactory::createEmbeddingsPlatform($configuration),
            $configuration['vector_db_embeddings_model'],
        );
    }
}
