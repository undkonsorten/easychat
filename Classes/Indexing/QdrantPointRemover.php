<?php

namespace Undkonsorten\Easychat\Indexing;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PointRemoverInterface for Qdrant. Needed because the symfony/ai-qdrant-store 0.1
 * Store has no remove() yet.
 */
class QdrantPointRemover implements PointRemoverInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $endpointUrl,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly string $collectionName,
    ) {}

    public function remove(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->httpClient->request('POST', sprintf('%s/collections/%s/points/delete', $this->endpointUrl, $this->collectionName), [
            'headers' => ['api-key' => $this->apiKey],
            'query' => ['wait' => 'true'],
            'json' => ['points' => array_values($ids)],
        ])->toArray();
    }
}
