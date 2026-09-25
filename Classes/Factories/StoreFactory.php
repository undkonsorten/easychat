<?php

namespace Undkonsorten\Easychat\Factories;

use Symfony\AI\Store\Bridge\Qdrant\Store;
use Symfony\Component\HttpClient\HttpClient;

class StoreFactory
{
    /**
     * @throws \Exception
     */
    public static function create(string $store, string $url, string $apiKey, string $dbName, int $dimensions): Store
    {
        return match ($store) {
            'qdrant' => self::createQdrant($url, $apiKey, $dbName, $dimensions),
            default => throw new \Exception(sprintf('Vector store "%s" is not supported.', $store), 3976784254),
        };
    }

    protected static function createQdrant(string $url, string $apiKey, string $collection, int $dimensions, string $distance = 'Dot'): Store
    {
        if (!class_exists(Store::class)) {
            throw new \Exception('symfony/ai-qdrant-store is not installed', 2326330016);
        }
        return new Store(
            HttpClient::create(),
            $url,
            $apiKey,
            $collection,
            $dimensions,
            $distance
        );
    }
}
