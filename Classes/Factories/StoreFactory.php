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
        switch ($store) {
            case 'qdrant':
                return self::createQdrant($url, $apiKey, $dbName, $dimensions);
            default:
                throw new \Exception(sprintf('Vector store "%s" is not supported.', $store));
        }
    }

    protected static function createQdrant(string $url, string $apiKey, string $collection, int $dimensions, string $distance = 'Dot'): Store
    {
        if(!class_exists('Symfony\AI\Store\Bridge\Qdrant\Store')){
            throw new \Exception('symfony/ai-qdrant-store is not installed');
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
