<?php

defined('TYPO3') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTcaSelectItem(
    'sys_reaction',
    'reaction_type',
    [
        'label' => \Undkonsorten\Easychat\Reaction\ChatReaction::getDescription(),
        'value' => \Undkonsorten\Easychat\Reaction\ChatReaction::getType(),
        'icon' => \Undkonsorten\Easychat\Reaction\ChatReaction::getIconIdentifier(),
    ]
);
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns(
    'sys_reaction',
    [
        'easychat_configuration' => [
            'label' => 'Chat configuration',
            'description' => 'Choose a chat configuration',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'size' => 1,
                'maxitems' => 1,
            ],
        ],
    ]
);


$GLOBALS['TCA']['sys_reaction']['ctrl']['typeicon_classes'][\Undkonsorten\Easychat\Reaction\ChatReaction::getType()] = \Undkonsorten\Easychat\Reaction\ChatReaction::getIconIdentifier();

$GLOBALS['TCA']['sys_reaction']['palettes']['easychatConfiguration'] = [
    'label' => 'LLL:EXT:reactions/Resources/Private/Language/locallang_db.xlf:palette.additional',
    'showitem' => 'easychat_configuration',
];

$GLOBALS['TCA']['sys_reaction']['types'][\Undkonsorten\Easychat\Reaction\ChatReaction::getType()] = [
    'showitem' => '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
        --palette--;;config,
        --palette--;;easychatConfiguration,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
        --palette--;;access',
];
