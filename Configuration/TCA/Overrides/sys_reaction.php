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
    'label' => 'reactions.db:palette.additional',
    'showitem' => 'easychat_configuration',
];

$GLOBALS['TCA']['sys_reaction']['types'][ChatReaction::getType()] = [
    'showitem' => '
        --div--;core.form.tabs:general,
        --palette--;;config,
        --palette--;;easychatConfiguration,
        --div--;core.form.tabs:access,
        --palette--;;access',
];
