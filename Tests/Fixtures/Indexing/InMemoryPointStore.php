<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Fixtures\Indexing;

use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\StoreInterface;

/**
 * Store over one array; add() upserts by id, like every real store.
 */
final class InMemoryPointStore implements StoreInterface
{
    /** @var array<string, VectorDocumentInterface> */
    private array $points = [];

    public function add(VectorDocumentInterface|array $documents): void
    {
        if ($documents instanceof VectorDocumentInterface) {
            $documents = [$documents];
        }
        foreach ($documents as $document) {
            $this->points[(string)$document->getId()] = $document;
        }
    }

    public function remove(string|array $ids, array $options = []): void
    {
        foreach ((array)$ids as $id) {
            unset($this->points[$id]);
        }
    }

    public function clear(array $options = []): void
    {
        $this->points = [];
    }

    public function query(QueryInterface $query, array $options = []): iterable
    {
        return array_values($this->points);
    }

    public function supports(string $queryClass): bool
    {
        return true;
    }

    public function count(): int
    {
        return count($this->points);
    }

    /**
     * @return array<string, VectorDocumentInterface> keyed by point id
     */
    public function points(): array
    {
        return $this->points;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return list<string> the _text payload of every point whose metadata contains all of $metadata
     */
    public function textsMatching(array $metadata): array
    {
        $texts = [];
        foreach ($this->points as $point) {
            if (array_intersect_key($point->getMetadata()->getArrayCopy(), $metadata) == $metadata) {
                $texts[] = $point->getMetadata()->getText();
            }
        }

        return $texts;
    }

    /**
     * @return list<string> the _text payload of every point whose uri ends with $uriSuffix
     */
    public function textsOf(string $uriSuffix): array
    {
        $texts = [];
        foreach ($this->points as $point) {
            if (str_ends_with((string)$point->getMetadata()['uri'], $uriSuffix)) {
                $texts[] = $point->getMetadata()->getText();
            }
        }

        return $texts;
    }
}
