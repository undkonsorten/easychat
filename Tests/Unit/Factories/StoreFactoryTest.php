<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Factories;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Bridge\Qdrant\Store;
use Undkonsorten\Easychat\Factories\StoreFactory;

final class StoreFactoryTest extends TestCase
{
    public function testCreateReturnsQdrantStoreForQdrantType(): void
    {
        $store = StoreFactory::create('qdrant', 'http://qdrant', 'api-key', 'easychat', 4096);

        self::assertInstanceOf(Store::class, $store);
    }

    public function testCreateThrowsForUnsupportedStoreType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Vector store "redis" is not supported.');

        StoreFactory::create('redis', 'http://redis', 'api-key', 'easychat', 4096);
    }
}
