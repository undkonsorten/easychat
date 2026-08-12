<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Functional\FormDataProvider;

use TYPO3\CMS\Backend\Form\FormDataProvider\TcaInputPlaceholders;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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

    /**
     * Regression test for a bug where editing ANY record (e.g. a page) failed with
     * "Too few arguments to function ModelValuePickerDataProvider::__construct()".
     *
     * FormEngine's OrderedProviderList instantiates every registered tcaDatabaseRecord
     * provider via `GeneralUtility::makeInstance($providerClassName)` with no
     * constructor arguments (see TYPO3\CMS\Backend\Form\FormDataGroup\OrderedProviderList).
     * makeInstance() only delegates to the DI container - and thus only performs
     * constructor injection - for services the container reports as *public*
     * (`self::$container->has($className)`); everything else falls back to a bare
     * `new $className()`.
     *
     * self::get() above is not equivalent: it falls back to the testing framework's
     * private container and would happily resolve a non-public service, so it passed
     * even while this extension's Services.yaml default of `public: false` left
     * ModelValuePickerDataProvider unreachable in production. Only makeInstance()
     * reproduces the real crash.
     */
    public function testProviderCanBeBuiltByGeneralUtilityMakeInstanceWithoutArgumentsLikeFormEngineDoes(): void
    {
        $provider = GeneralUtility::makeInstance(ModelValuePickerDataProvider::class);

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
