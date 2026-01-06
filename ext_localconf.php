<?php

declare(strict_types=1);

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use Undkonsorten\DeinFondsConsolidate\Controller\MotionController;
use Undkonsorten\DeinFondsConsolidate\Controller\OptInController;
use Undkonsorten\DeinFondsConsolidate\Routing\Aspect\IdentifierValueMapper;


(static function (): void {
    ExtensionUtility::configurePlugin(
        'Easychat',
        'EasychatFrontend',
        [
            \Undkonsorten\Easychat\Controller\EasychatController::class => 'chatFrontend',
        ],
        [
            MotionController::class => 'confirm',
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
