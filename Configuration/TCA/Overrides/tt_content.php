<?php
/** @noinspection PhpFullyQualifiedNameUsageInspection */
declare(strict_types=1);


use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility as ExtbaseExtensionUtility;

(function (): void {
    $pluginName = 'EasychatFrontend';
    $extensionName = "easychat";
    $upperCaseFirstPluginName = ucfirst($pluginName);
    $upperCaseFirstExtensionName = ucfirst($extensionName);
    $lowerCasedPluginName = strtolower($pluginName);
    $lowerCasedExtensionName = strtolower($extensionName);
    $extensionKey = GeneralUtility::camelCaseToLowerCaseUnderscored($extensionName);
    ExtbaseExtensionUtility::registerPlugin(
        $upperCaseFirstExtensionName,
        $upperCaseFirstPluginName,
        'LLL:EXT:'.$extensionKey.'/Resources/Private/Language/locallang_db.xlf:title.' . $pluginName
    );
    $GLOBALS['TCA']['tt_content']['types']['list']['subtypes_addlist'][$lowerCasedExtensionName.'_' . $lowerCasedPluginName] = 'pi_flexform';
    $GLOBALS['TCA']['tt_content']['types']['list']['subtypes_excludelist'][$lowerCasedExtensionName.'_' . $lowerCasedPluginName] = 'recursive,select_key,pages';
    ExtensionManagementUtility::addPiFlexFormValue(
        $lowerCasedExtensionName.'_' . $lowerCasedPluginName,
        'FILE:EXT:'.$extensionKey.'/Configuration/FlexForms/Easychat.xml'
    );
})();
