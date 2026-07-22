<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Lists models available on an OpenAI-compatible server (GET {baseUrl}/v1/models),
 * so backend forms can offer them as suggestions while still allowing free text.
 */
class ModelListService
{
    private HttpClientInterface $httpClient;

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @return string[] Sorted model IDs, or an empty array if the server is unset,
     *                   unreachable, or doesn't answer with the expected shape.
     */
    public function getAvailableModels(string $baseUrl, ?string $apiKey): array
    {
        if ($baseUrl === '') {
            return [];
        }

        try {
            $options = ['timeout' => 3];
            if ($apiKey) {
                $options['auth_bearer'] = $apiKey;
            }

            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/') . '/v1/models', $options);
            $data = $response->toArray();

            $modelIds = array_map(
                static fn (array $model): string => (string)($model['id'] ?? ''),
                $data['data'] ?? [],
            );
            $modelIds = array_filter($modelIds, static fn (string $id): bool => $id !== '');
            sort($modelIds);

            return $modelIds;
        } catch (Throwable) {
            return [];
        }
    }
}
