<?php

namespace Undkonsorten\Easychat\Indexing;

use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\StoreInterface;

/**
 * Everything IndexEventListener needs to write into, and delete from, one configuration's vector store.
 */
final readonly class VectorTarget
{
    public function __construct(
        public StoreInterface $store,
        public VectorizerInterface $vectorizer,
        public PointRemoverInterface $remover,
    ) {}
}
