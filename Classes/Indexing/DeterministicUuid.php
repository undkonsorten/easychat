<?php

namespace Undkonsorten\Easychat\Indexing;

use Symfony\Component\Uid\Uuid;

/**
 * Derives a stable UUID from an arbitrary seed string, so re-indexing the same
 * page/file/chunk overwrites its previous vector instead of duplicating it.
 */
final class DeterministicUuid
{
    public static function generate(string $seed): Uuid
    {
        return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), $seed);
    }
}
