<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Fixtures\Indexing;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorizerInterface;

/**
 * Stands in for the embeddings API: every document gets the same fixed vector,
 * with id and metadata passed through exactly like the real Vectorizer does.
 */
final class FakeVectorizer implements VectorizerInterface
{
    public function __construct(
        private readonly int $dimensions = 4,
    ) {}

    public function vectorize(string|\Stringable|EmbeddableDocumentInterface|array $values, array $options = []): Vector|VectorDocument|array
    {
        if (!\is_array($values)) {
            throw new \LogicException('FakeVectorizer only supports lists of documents.');
        }

        return array_map(
            fn (EmbeddableDocumentInterface $document): VectorDocument => new VectorDocument(
                $document->getId(),
                new Vector(array_fill(0, $this->dimensions, 0.5)),
                $document->getMetadata(),
            ),
            $values,
        );
    }
}
