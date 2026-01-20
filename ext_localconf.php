<?php

declare(strict_types=1);

use Undkonsorten\Easychat\Controller\EasychatController;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;



(static function (): void {
    ExtensionUtility::configurePlugin(
        'Easychat',
        'EasychatFrontend',
        [
            EasychatController::class => 'chatFrontend',
        ],
        [
            EasychatController::class => 'chatFrontend',
        ],
        ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
    );

    if (getenv('TYPO3_MINIMUM_LOGLEVEL')) {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['Undkonsorten']['MyMotions'] = [
            'writerConfiguration' => [
                getenv('TYPO3_MINIMUM_LOGLEVEL') => [
                    FileWriter::class => [
                        // configuration for the writer
                        'logFile' => Environment::getVarPath() . '/log/easychat.log'
                    ]
                ]
            ]
        ];
    }
})();
