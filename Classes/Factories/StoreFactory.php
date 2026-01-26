<?php

namespace Undkonsorten\Easychat\Factories;

use Symfony\AI\Store\Bridge\Qdrant\Store;
use Symfony\Component\HttpClient\HttpClient;

class StoreFactory
{
    /**
     * @throws \Exception
     */
    public static function create(string $store, string $url, string $apiKey, string $dbName)
    {
        switch ($store) {
            case 'qdrant':
                return self::createQdrant($url,$apiKey,$dbName);

        }
    }

    protected static function createQdrant(string $url, string $apiKey, string $collection, int $dimensions = 4096, string $distance = 'Dot'): Store
    {
        if(!class_exists('Symfony\AI\Store\Bridge\Qdrant\Store')){
            throw new \Exception('symfony/ai-qdrant-store is not installed');
        }
        return new Store(
            HttpClient::create(),
            $url,
            $apiKey,
            $collection,
            4096,
            'Dot'
        );
    }
}
