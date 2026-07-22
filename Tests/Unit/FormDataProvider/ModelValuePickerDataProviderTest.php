<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\FormDataProvider;

use PHPUnit\Framework\TestCase;
use Undkonsorten\Easychat\FormDataProvider\ModelValuePickerDataProvider;
use Undkonsorten\Easychat\Service\ModelListService;

final class ModelValuePickerDataProviderTest extends TestCase
{
    public function testAddDataIsNoopForOtherTables(): void
    {
        $modelListService = $this->createMock(ModelListService::class);
        $modelListService->expects(self::never())->method('getAvailableModels');

        $provider = new ModelValuePickerDataProvider($modelListService);

        $result = $provider->addData($this->buildResult('pages', []));

        self::assertSame([], $result['processedTca']['columns']);
    }

    public function testPopulatesModelValuePickerItemsFromUrlAndApiKey(): void
    {
        $modelListService = $this->createMock(ModelListService::class);
        $modelListService->expects(self::once())
            ->method('getAvailableModels')
            ->with('https://llm.example.com', 'chat-key')
            ->willReturn(['gpt-4o', 'gpt-4o-mini']);

        $provider = new ModelValuePickerDataProvider($modelListService);

        $result = $provider->addData($this->buildResult(
            'tx_easychat_configuration',
            ['url' => 'https://llm.example.com', 'api_key' => 'chat-key'],
            ['model'],
        ));

        self::assertSame(
            [['gpt-4o', 'gpt-4o'], ['gpt-4o-mini', 'gpt-4o-mini']],
            $result['processedTca']['columns']['model']['config']['valuePicker']['items'],
        );
    }

    public function testPopulatesEmbeddingsValuePickerUsingEmbeddingsUrlWhenSet(): void
    {
        $modelListService = $this->createMock(ModelListService::class);
        $modelListService->expects(self::once())
            ->method('getAvailableModels')
            ->with('https://embeddings.example.com', 'embeddings-key')
            ->willReturn(['text-embedding-3-small']);

        $provider = new ModelValuePickerDataProvider($modelListService);

        $result = $provider->addData($this->buildResult(
            'tx_easychat_configuration',
            [
                'url' => 'https://llm.example.com',
                'api_key' => 'chat-key',
                'vector_db_embeddings_url' => 'https://embeddings.example.com',
                'vector_db_embeddings_api_key' => 'embeddings-key',
            ],
            ['vector_db_embeddings_model'],
        ));

        self::assertSame(
            [['text-embedding-3-small', 'text-embedding-3-small']],
            $result['processedTca']['columns']['vector_db_embeddings_model']['config']['valuePicker']['items'],
        );
    }

    public function testPopulatesEmbeddingsValuePickerFallingBackToChatUrlAndApiKeyWhenEmbeddingsOverrideEmpty(): void
    {
        $modelListService = $this->createMock(ModelListService::class);
        $modelListService->expects(self::once())
            ->method('getAvailableModels')
            ->with('https://llm.example.com', 'chat-key')
            ->willReturn([]);

        $provider = new ModelValuePickerDataProvider($modelListService);

        $provider->addData($this->buildResult(
            'tx_easychat_configuration',
            [
                'url' => 'https://llm.example.com',
                'api_key' => 'chat-key',
                'vector_db_embeddings_url' => '',
                'vector_db_embeddings_api_key' => '',
            ],
            ['vector_db_embeddings_model'],
        ));
    }

    public function testSkipsColumnsNotPresentInProcessedTca(): void
    {
        $modelListService = $this->createMock(ModelListService::class);
        $modelListService->expects(self::never())->method('getAvailableModels');

        $provider = new ModelValuePickerDataProvider($modelListService);

        $result = $provider->addData($this->buildResult(
            'tx_easychat_configuration',
            ['url' => 'https://llm.example.com', 'api_key' => 'chat-key'],
            [],
        ));

        self::assertArrayNotHasKey('model', $result['processedTca']['columns']);
        self::assertArrayNotHasKey('vector_db_embeddings_model', $result['processedTca']['columns']);
    }

    /**
     * @param array<string, mixed> $databaseRow
     * @param string[] $columnsWithValuePicker
     */
    private function buildResult(string $tableName, array $databaseRow, array $columnsWithValuePicker = []): array
    {
        $columns = [];
        foreach ($columnsWithValuePicker as $columnName) {
            $columns[$columnName] = ['config' => ['type' => 'input', 'valuePicker' => ['items' => []]]];
        }

        return [
            'tableName' => $tableName,
            'databaseRow' => $databaseRow,
            'processedTca' => ['columns' => $columns],
        ];
    }
}
