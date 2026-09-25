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

    if ((new Typo3Version())->getMajorVersion() < 14) {
        ExtensionManagementUtility::addPiFlexFormValue(
            '*',
            $flexForm,
            $pluginSignature,
        );

        ExtensionManagementUtility::addToAllTCAtypes(
            'tt_content',
            '--div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.plugin, pi_flexform',
            $pluginSignature,
            'after:palette:headers'
        );
    }
});
