<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Errors that only occur when analysing against TYPO3 v13: the code calls v14 APIs behind a
 * version check, which PHPStan does not follow. Remove together with TYPO3 v13 support.
 */
if ((new Typo3Version())->getMajorVersion() >= 14) {
    return [];
}

return [
    'parameters' => [
        'ignoreErrors' => [
            [
                'identifier' => 'method.notFound',
                'message' => '#DocHeaderComponent::setShortcutContext\(\)#',
                'path' => __DIR__ . '/../../Classes/Controller/SessionController.php',
            ],
            [
                'identifier' => 'arguments.count',
                'message' => '#ExtensionUtility::registerPlugin\(\) invoked with 7 parameters#',
                'path' => __DIR__ . '/../../Configuration/TCA/Overrides/tt_content.php',
            ],
        ],
    ],
];
