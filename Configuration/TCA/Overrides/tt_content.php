<?php

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

call_user_func(static function () {
    $extensionKey = 'Easychat';
    $pluginName = 'EasychatFrontend';
    $pluginTitle = 'LLL:EXT:easychat/Resources/Private/Language/locallang.xlf:easychat_frontend_plugin_title';
    $flexForm = 'FILE:EXT:easychat/Configuration/Flexforms/Easychat.xml';

    // Since TYPO3 v14 registerPlugin() takes the FlexForm and adds the plugin tab with pi_flexform itself.
    // TYPO3 v13 has no such argument, PHP ignores it there.
    $pluginSignature = ExtensionUtility::registerPlugin(
        $extensionKey,
        $pluginName,
        $pluginTitle,
        'tx-easychat-extension',
        'Easychat',
        'LLL:EXT:easychat/Resources/Private/Language/locallang.xlf:easychat_frontend_plugin_description',
        $flexForm,
    );

    // The core only derives a default form for a CType plugin by copying the "header" type.
    // Site packages that remove the fluid_styled_content types (and load before this extension)
    // leave the plugin without a type definition, and FormEngine as well as the page module
    // then report it as an invalid value. So define the form explicitly, on TYPO3 v13 and v14.
    $GLOBALS['TCA']['tt_content']['types'][$pluginSignature]['showitem'] = '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
            --palette--;;general,
            --palette--;;headers,
        --div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.plugin,
            pi_flexform,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:language,
            --palette--;;language,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
            --palette--;;hidden,
            --palette--;;access,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
            categories,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:notes,
            rowDescription,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:extended,
    ';

    if ((new Typo3Version())->getMajorVersion() < 14) {
        ExtensionManagementUtility::addPiFlexFormValue(
            '*',
            $flexForm,
            $pluginSignature,
        );
    }
});
