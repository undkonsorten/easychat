<?php

namespace Undkonsorten\Easychat\Factories;

use Symfony\AI\Store\Bridge\Qdrant\StoreFactory as QdrantStoreFactory;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\HttpClient\HttpClient;

class StoreFactory
{
    /**
     * @throws \Exception
     */
    public static function create(string $store, string $url, string $apiKey, string $dbName, int $dimensions): StoreInterface&ManagedStoreInterface
    {
        return match ($store) {
            'qdrant' => self::createQdrant($url, $apiKey, $dbName, $dimensions),
            default => throw new \Exception(sprintf('Vector store "%s" is not supported.', $store), 3976784254),
        };
    }

    protected static function createQdrant(string $url, string $apiKey, string $collection, int $dimensions, string $distance = 'Dot'): StoreInterface&ManagedStoreInterface
    {
        if (!class_exists(QdrantStoreFactory::class)) {
            throw new \Exception('symfony/ai-qdrant-store is not installed', 2326330016);
        }
        return QdrantStoreFactory::create(
            $collection,
            $url,
            $apiKey !== '' ? $apiKey : null,
            HttpClient::create(),
            $dimensions,
            $distance,
        );
    }
}
