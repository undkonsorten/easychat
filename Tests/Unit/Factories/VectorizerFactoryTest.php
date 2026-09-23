<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Factories;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\Vectorizer;
use Undkonsorten\Easychat\Factories\VectorizerFactory;

final class VectorizerFactoryTest extends TestCase
{
    public function testCreateBuildsAVectorizerWithoutContactingThePlatform(): void
    {
        // An unreachable url: building the vectorizer must not send anything yet.
        $configuration = [
            'url' => 'http://llm.invalid',
            'api_key' => 'chat-key',
            'vector_db_embeddings_url' => '',
            'vector_db_embeddings_api_key' => '',
            'vector_db_embeddings_model' => 'text-embedding-3-small',
        ];

        self::assertInstanceOf(Vectorizer::class, VectorizerFactory::create($configuration));
    }
}
