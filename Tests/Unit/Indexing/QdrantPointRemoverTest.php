<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Indexing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Undkonsorten\Easychat\Indexing\QdrantPointRemover;

final class QdrantPointRemoverTest extends TestCase
{
    public function testRemoveSendsADeleteRequestForTheCollection(): void
    {
        $requests = [];
        $remover = $this->createRemover($requests);

        $remover->remove(['0f5b1c7e-0000-4000-8000-000000000001', '0f5b1c7e-0000-4000-8000-000000000002']);

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('http://qdrant:6333/collections/easychat/points/delete?wait=true', $requests[0]['url']);
        self::assertContains('api-key: secret', $requests[0]['headers']);
        self::assertSame(
            ['points' => ['0f5b1c7e-0000-4000-8000-000000000001', '0f5b1c7e-0000-4000-8000-000000000002']],
            json_decode($requests[0]['body'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testRemoveSendsTheIdsAsAListEvenIfTheirKeysAreNotSequential(): void
    {
        $requests = [];
        $remover = $this->createRemover($requests);

        // array_diff() and friends keep keys; a keyed array would serialize to a JSON object.
        $remover->remove([3 => 'id-a', 7 => 'id-b']);

        self::assertSame('{"points":["id-a","id-b"]}', $requests[0]['body']);
    }

    public function testRemoveWithoutIdsSendsNoRequest(): void
    {
        $requests = [];
        $remover = $this->createRemover($requests);

        $remover->remove([]);

        self::assertSame([], $requests);
    }

    public function testRemoveThrowsOnAClientError(): void
    {
        $requests = [];
        $remover = $this->createRemover($requests, new MockResponse('{"status":{"error":"Not found"}}', ['http_code' => 404]));

        $this->expectException(ClientException::class);
        $remover->remove(['id-a']);
    }

    public function testRemoveThrowsOnAServerError(): void
    {
        $requests = [];
        $remover = $this->createRemover($requests, new MockResponse('', ['http_code' => 500]));

        $this->expectException(ServerException::class);
        $remover->remove(['id-a']);
    }

    /**
     * @param list<array{method: string, url: string, headers: string[], body: string}> $requests
     */
    private function createRemover(array &$requests, ?MockResponse $response = null): QdrantPointRemover
    {
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests, $response): MockResponse {
                $requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'headers' => $options['headers'],
                    'body' => $options['body'],
                ];

                return $response ?? new MockResponse('{"result":{"status":"completed"},"status":"ok"}');
            },
        );

        return new QdrantPointRemover($httpClient, 'http://qdrant:6333', 'secret', 'easychat');
    }
}
