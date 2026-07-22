<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Undkonsorten\Easychat\Service\ModelListService;

final class ModelListServiceTest extends TestCase
{
    public function testReturnsEmptyArrayWhenBaseUrlIsEmptyWithoutMakingAnHttpCall(): void
    {
        $httpClient = new MockHttpClient(function (): never {
            self::fail('No HTTP call should be made when the base URL is empty.');
        });

        $service = new ModelListService($httpClient);

        self::assertSame([], $service->getAvailableModels('', 'some-key'));
    }

    public function testReturnsSortedModelIdsOnSuccessfulResponse(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'data' => [
                ['id' => 'gpt-4o-mini'],
                ['id' => 'gpt-4o'],
                ['id' => 'gpt-3.5-turbo'],
            ],
        ], JSON_THROW_ON_ERROR)));

        $service = new ModelListService($httpClient);

        self::assertSame(
            ['gpt-3.5-turbo', 'gpt-4o', 'gpt-4o-mini'],
            $service->getAvailableModels('https://llm.example.com', null),
        );
    }

    public function testFiltersOutEntriesWithMissingOrEmptyId(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'data' => [
                ['id' => 'gpt-4o'],
                ['id' => ''],
                ['object' => 'model'],
            ],
        ], JSON_THROW_ON_ERROR)));

        $service = new ModelListService($httpClient);

        self::assertSame(['gpt-4o'], $service->getAvailableModels('https://llm.example.com', null));
    }

    public function testReturnsEmptyArrayOnNetworkFailure(): void
    {
        $httpClient = new MockHttpClient(static fn (): never => throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused'));

        $service = new ModelListService($httpClient);

        self::assertSame([], $service->getAvailableModels('https://unreachable.example.com', null));
    }

    public function testReturnsEmptyArrayOnNonSuccessfulHttpStatus(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('Unauthorized', ['http_code' => 401]));

        $service = new ModelListService($httpClient);

        self::assertSame([], $service->getAvailableModels('https://llm.example.com', 'wrong-key'));
    }

    public function testReturnsEmptyArrayOnMalformedJsonResponse(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('not json'));

        $service = new ModelListService($httpClient);

        self::assertSame([], $service->getAvailableModels('https://llm.example.com', null));
    }

    public function testSendsBearerTokenAndModelsPathWhenApiKeyProvided(): void
    {
        $capturedOptions = null;
        $capturedUrl = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions, &$capturedUrl): MockResponse {
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse(json_encode(['data' => []], JSON_THROW_ON_ERROR));
        });

        $service = new ModelListService($httpClient);
        $service->getAvailableModels('https://llm.example.com/', 'sk-test-key');

        self::assertSame('https://llm.example.com/v1/models', $capturedUrl);
        self::assertStringContainsString('Bearer sk-test-key', implode(' ', $capturedOptions['headers'] ?? []));
    }

    public function testOmitsAuthorizationHeaderWhenNoApiKeyProvided(): void
    {
        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse(json_encode(['data' => []], JSON_THROW_ON_ERROR));
        });

        $service = new ModelListService($httpClient);
        $service->getAvailableModels('https://llm.example.com', null);

        self::assertStringNotContainsString('Authorization', implode(' ', $capturedOptions['headers'] ?? []));
    }
}
