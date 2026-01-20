<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Undkonsorten\Easychat\Reaction\ChatReaction;

defined('TYPO3') || die();

ExtensionManagementUtility::addTcaSelectItem(
    'sys_reaction',
    'reaction_type',
    [
        'label' => ChatReaction::getDescription(),
        'value' => ChatReaction::getType(),
        'icon' => ChatReaction::getIconIdentifier(),
    ]
);
ExtensionManagementUtility::addTCAcolumns(
    'sys_reaction',
    [
        'easychat_configuration' => [
            'label' => 'Chat configuration',
            'description' => 'Choose a chat configuration',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_easychat_configuration',
                'size' => 1,
                'maxitems' => 1,
            ],
        ],
    ]
);


$GLOBALS['TCA']['sys_reaction']['ctrl']['typeicon_classes'][ChatReaction::getType()] = ChatReaction::getIconIdentifier();

$GLOBALS['TCA']['sys_reaction']['palettes']['easychatConfiguration'] = [
    'label' => 'LLL:EXT:reactions/Resources/Private/Language/locallang_db.xlf:palette.additional',
    'showitem' => 'easychat_configuration',
];

$GLOBALS['TCA']['sys_reaction']['types'][ChatReaction::getType()] = [
    'showitem' => '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
        --palette--;;config,
        --palette--;;easychatConfiguration,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
        --palette--;;access',
];
