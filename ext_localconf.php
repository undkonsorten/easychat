<?php

declare(strict_types=1);

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;



(static function (): void {
    ExtensionUtility::configurePlugin(
        'Easychat',
        'EasychatFrontend',
        [
            \Undkonsorten\Easychat\Controller\EasychatController::class => 'chatFrontend',
        ],
        [
            \Undkonsorten\Easychat\Controller\EasychatController::class => 'chatFrontend',
        ]
    );

    if (getenv('TYPO3_MINIMUM_LOGLEVEL')) {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['Undkonsorten']['MyMotions'] = [
            'writerConfiguration' => [
                getenv('TYPO3_MINIMUM_LOGLEVEL') => [
                    \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                        // configuration for the writer
                        'logFile' => \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/log/easychat.log'
                    ]
                ]
            ]
        ];
    }
})();
