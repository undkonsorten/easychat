<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\FormDataProvider;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;
use Undkonsorten\Easychat\Service\ModelListService;

/**
 * Populates the valuePicker of tx_easychat_configuration's model fields with the
 * models available on the server configured on that same record, so editors get a
 * dropdown of real options while free text is still accepted as a fallback.
 */
final readonly class ModelValuePickerDataProvider implements FormDataProviderInterface
{
    public function __construct(
        private ModelListService $modelListService,
    ) {}

    public function addData(array $result): array
    {
        if ($result['tableName'] !== 'tx_easychat_configuration') {
            return $result;
        }

        $row = $result['databaseRow'];

        if (isset($result['processedTca']['columns']['model'])) {
            $result['processedTca']['columns']['model']['config']['valuePicker']['items'] = $this->buildItems(
                $this->modelListService->getAvailableModels(
                    (string)($row['url'] ?? ''),
                    (string)($row['api_key'] ?? ''),
                ),
            );
        }

        if (isset($result['processedTca']['columns']['vector_db_embeddings_model'])) {
            $embeddingsUrl = (string)($row['vector_db_embeddings_url'] ?? '') ?: (string)($row['url'] ?? '');
            $embeddingsApiKey = (string)($row['vector_db_embeddings_api_key'] ?? '') ?: (string)($row['api_key'] ?? '');

            $result['processedTca']['columns']['vector_db_embeddings_model']['config']['valuePicker']['items'] = $this->buildItems(
                $this->modelListService->getAvailableModels($embeddingsUrl, $embeddingsApiKey),
            );
        }

        return $result;
    }

    /**
     * @param string[] $modelIds
     */
    private function buildItems(array $modelIds): array
    {
        return array_map(static fn (string $modelId): array => [$modelId, $modelId], $modelIds);
    }
}
