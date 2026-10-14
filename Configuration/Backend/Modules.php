<?php

use TYPO3\CMS\Core\Information\Typo3Version;
use Undkonsorten\Easychat\Controller\SessionController;

return [
    'easychat' => [
        // TYPO3 v14 renamed the "web" main module to "content" and keeps "web" as an alias
        'parent' => (new Typo3Version())->getMajorVersion() >= 14 ? 'content' : 'web',
        // Sessions are not workspace-aware, so the module is available in every workspace
        'workspaces' => '*',
        'path' => '/module/page/easychat',
        'access' => 'user',
        'icon' => 'EXT:easychat/Resources/Public/Icons/Extension.svg',
        'labels' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_easychat.xlf',
        'extensionName' => 'Easychat',
        'controllerActions' => [
            SessionController::class => [
                'list', 'show', 'delete', 'export', 'exportSettings',
            ],
        ],
    ],
];
