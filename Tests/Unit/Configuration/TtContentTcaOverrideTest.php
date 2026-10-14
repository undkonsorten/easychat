<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;

/**
 * Loads Configuration/TCA/Overrides/tt_content.php against a tt_content TCA
 * that has no "header" type any more. Site packages remove the
 * fluid_styled_content types and may be loaded before this extension, and
 * the core derives the default form of a CType plugin only by copying the
 * "header" type. Without an explicit type definition the plugin would then
 * show up as "INVALID VALUE" in the backend.
 *
 * The CI runs this against TYPO3 13.4 and 14.3.
 */
final class TtContentTcaOverrideTest extends TestCase
{
    private const OVERRIDE_FILE = __DIR__ . '/../../../Configuration/TCA/Overrides/tt_content.php';
    private const PLUGIN_SIGNATURE = 'easychat_easychatfrontend';
    private const FLEX_FORM = 'FILE:EXT:easychat/Configuration/Flexforms/Easychat.xml';

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal tt_content TCA without any fluid_styled_content type, "header" included.
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => [],
            'columns' => [
                'CType' => [
                    'config' => [
                        'items' => [],
                    ],
                ],
                'pi_flexform' => [
                    'config' => [
                        'ds' => [],
                    ],
                ],
            ],
            'types' => [
                'text' => [
                    'showitem' => 'bodytext',
                ],
            ],
        ];
        // Set by ExtensionUtility::configurePlugin() in ext_localconf.php, read by registerPlugin() on TYPO3 v13.
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions']['Easychat']['plugins']['EasychatFrontend']['pluginType'] = 'CType';

        require self::OVERRIDE_FILE;
    }

    public function testPluginTypeIsDefinedWithoutHeaderType(): void
    {
        $types = $GLOBALS['TCA']['tt_content']['types'];

        self::assertArrayHasKey(self::PLUGIN_SIGNATURE, $types);
        self::assertArrayHasKey('showitem', $types[self::PLUGIN_SIGNATURE]);
        self::assertStringContainsString('--palette--;;headers', $types[self::PLUGIN_SIGNATURE]['showitem']);
        self::assertStringContainsString('pi_flexform', $types[self::PLUGIN_SIGNATURE]['showitem']);
    }

    public function testPluginIsRegisteredAsContentType(): void
    {
        $cTypes = array_column($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'], 'value');

        self::assertContains(self::PLUGIN_SIGNATURE, $cTypes);
        self::assertSame(
            'tx-easychat-extension',
            $GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes'][self::PLUGIN_SIGNATURE],
        );
    }

    public function testFlexFormIsRegisteredForPlugin(): void
    {
        // TYPO3 v13 registers the FlexForm in the pi_flexform column, v14 in the type's columnsOverrides.
        $flexForm = $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']['*,' . self::PLUGIN_SIGNATURE]
            ?? $GLOBALS['TCA']['tt_content']['types'][self::PLUGIN_SIGNATURE]['columnsOverrides']['pi_flexform']['config']['ds']
            ?? null;

        self::assertSame(self::FLEX_FORM, $flexForm);
    }

    public function testOtherTypesStayUntouched(): void
    {
        self::assertSame('bodytext', $GLOBALS['TCA']['tt_content']['types']['text']['showitem']);
    }
}
