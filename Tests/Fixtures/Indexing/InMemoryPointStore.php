<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Fixtures\Indexing;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;
use Undkonsorten\Easychat\Indexing\PointRemoverInterface;

/**
 * Store and point remover over one array; add() upserts by id, like every real store.
 */
final class InMemoryPointStore implements StoreInterface, PointRemoverInterface
{
    /** @var array<string, VectorDocument> */
    private array $points = [];

    public function add(VectorDocument ...$documents): void
    {
        foreach ($documents as $document) {
            $this->points[$document->id->toRfc4122()] = $document;
        }
    }

    public function query(Vector $vector, array $options = []): iterable
    {
        return array_values($this->points);
    }

    public function remove(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->points[$id]);
        }
    }

    /**
     * @return array<string, VectorDocument> keyed by point id
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
            if (array_intersect_key($point->metadata->getArrayCopy(), $metadata) == $metadata) {
                $texts[] = $point->metadata->getText();
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
            if (str_ends_with((string)$point->metadata['uri'], $uriSuffix)) {
                $texts[] = $point->metadata->getText();
            }
        }

        return $texts;
    }
}
