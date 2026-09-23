<?php

namespace Undkonsorten\Easychat\Indexing;

/**
 * Deletes points from a vector store by id.
 *
 * Mirrors StoreInterface::remove() of symfony/ai-store >= 0.4, which every store bridge
 * implements. Once symfony/ai is upgraded this interface can go and the store itself is
 * used instead — see Documentation/Symfony-AI-Upgrade-Notes.md.
 */
interface PointRemoverInterface
{
    /**
     * @param string[] $ids RFC 4122 point ids
     */
    public function remove(array $ids): void;
}
