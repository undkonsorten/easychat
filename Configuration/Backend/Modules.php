<?php

use Undkonsorten\Easychat\Controller\SessionController;

return [
    'easychat' => [
        'parent' => 'web',
        #'position' => ['after' => 'scheduler'],
        'workspaces' => 'scheduler',
        'path' => '/module/page/easychat',
        'access' => 'user',
        'icon' => 'EXT:easychat/Resources/Public/Icons/Extension.svg',
        'labels' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_easychat.xlf',
        'extensionName' => 'Easychat',
        'controllerActions' => [
            SessionController::class => [
                'list', 'show', 'delete',
            ],
        ],
    ]
];
