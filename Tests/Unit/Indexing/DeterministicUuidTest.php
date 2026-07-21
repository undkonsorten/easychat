<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Indexing;

use PHPUnit\Framework\TestCase;
use Undkonsorten\Easychat\Indexing\DeterministicUuid;

final class DeterministicUuidTest extends TestCase
{
    public function testSameSeedProducesTheSameId(): void
    {
        $seed = 'page:main:0:1';

        self::assertTrue(DeterministicUuid::generate($seed)->equals(DeterministicUuid::generate($seed)));
    }

    public function testDifferentSeedsProduceDifferentIds(): void
    {
        self::assertFalse(
            DeterministicUuid::generate('page:main:0:1')->equals(DeterministicUuid::generate('page:main:0:2'))
        );
    }

    public function testChunkSeedsOfTheSamePageProduceDifferentIds(): void
    {
        $pageSeed = 'page:main:0:1';

        self::assertFalse(
            DeterministicUuid::generate($pageSeed . '#0')->equals(DeterministicUuid::generate($pageSeed . '#1'))
        );
    }
}
