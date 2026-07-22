<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\FormDataProvider;

use TYPO3\CMS\Backend\Form\FormDataProvider\TcaInputPlaceholders;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Undkonsorten\Easychat\FormDataProvider\ModelValuePickerDataProvider;

/**
 * Covers the parts of the model-valuepicker feature that only a real TYPO3
 * bootstrap can verify: that ext_localconf.php actually registers
 * ModelValuePickerDataProvider into FormEngine's tcaDatabaseRecord group, and
 * that the real DI container can build it (autowiring ModelListService and its
 * HttpClientInterface argument). The transformation logic itself is covered by
 * the unit tests in Tests/Unit/FormDataProvider/.
 */
final class ModelValuePickerDataProviderTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['undkonsorten/easychat'];

    public function testProviderIsRegisteredInTheTcaDatabaseRecordFormDataGroup(): void
    {
        $registered = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'][ModelValuePickerDataProvider::class] ?? null;

        self::assertNotNull($registered, 'ModelValuePickerDataProvider must be registered in ext_localconf.php');
        self::assertContains(TcaInputPlaceholders::class, $registered['depends'] ?? []);
    }

    public function testProviderIsAutowireableFromTheRealContainer(): void
    {
        $provider = $this->get(ModelValuePickerDataProvider::class);

        self::assertInstanceOf(ModelValuePickerDataProvider::class, $provider);
    }

    public function testAddDataDegradesToEmptyValuePickerItemsWithoutNetworkAccessWhenNoServerIsConfigured(): void
    {
        $provider = $this->get(ModelValuePickerDataProvider::class);

        $result = $provider->addData([
            'tableName' => 'tx_easychat_configuration',
            'databaseRow' => ['url' => '', 'api_key' => '', 'vector_db_embeddings_url' => '', 'vector_db_embeddings_api_key' => ''],
            'processedTca' => [
                'columns' => [
                    'model' => ['config' => ['type' => 'input', 'valuePicker' => ['items' => []]]],
                    'vector_db_embeddings_model' => ['config' => ['type' => 'input', 'valuePicker' => ['items' => []]]],
                ],
            ],
        ]);

        self::assertSame([], $result['processedTca']['columns']['model']['config']['valuePicker']['items']);
        self::assertSame([], $result['processedTca']['columns']['vector_db_embeddings_model']['config']['valuePicker']['items']);
    }
}
