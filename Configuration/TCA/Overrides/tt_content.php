<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
call_user_func(static function () {
    $extensionKey = 'Easychat';
    $pluginName = 'EasychatFrontend';
    $pluginTitle = 'LLL:EXT:easychat/Resources/Private/Language/locallang.xlf:easychat_frontend_plugin_title';

    $pluginSignature = ExtensionUtility::registerPlugin(
        $extensionKey,
        $pluginName,
        $pluginTitle,
        '',
        'easychat'
    );

    ExtensionManagementUtility::addPiFlexFormValue(
        '*',
        'FILE:EXT:easychat/Configuration/Flexforms/Easychat.xml',
        $pluginSignature,
    );

    ExtensionManagementUtility::addToAllTCAtypes(
        'tt_content',
        'colPos',
        $pluginSignature,
    );
});
