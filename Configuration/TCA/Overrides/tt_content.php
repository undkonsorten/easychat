<?php
/** @noinspection PhpFullyQualifiedNameUsageInspection */
declare(strict_types=1);


use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

(function (): void {

    ExtensionUtility::registerPlugin(
        'Easychat',
        'EasychatFrontend',
        // @TODO localize
        'Easychat Frontend'
    );

})();
