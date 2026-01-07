<?php
declare(strict_types=1);
if (!defined('TYPO3')) {
    die('Access denied.');
}

return [
    'ctrl' => [
        'title'	=> 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration',
        'label' => 'name',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'versioningWS' => true,
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'searchFields' => 'name,model,url,',
        'iconfile' => 'EXT:easychat/Resources/Public/Icons/Extension.svg'
    ],
    'types' => [
        '1' => ['showitem' => 'sys_language_uid,l10n_parent,l10n_diffsource,hidden,--palette--;;1,name,model,url,api_key,system_message,priority,ttl,last_run,crdate,retries,--div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.access,starttime,endtime'],
    ],
    'palettes' => [
        '1' => ['showitem' => ''],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
            ],
        ],
        'starttime' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.starttime',
            'config' => [
                'type' => 'input',
                'renderType' => 'inputDateTime',
                'size' => 13,
                'eval' => 'datetime',
                'checkbox' => 0,
                'default' => 0,
                'range' => [
                    'lower' => mktime(0, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y'))
                ],
            ],
        ],
        'endtime' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.endtime',
            'config' => [
                'type' => 'input',
                'renderType' => 'inputDateTime',
                'size' => 13,
                'eval' => 'datetime',
                'checkbox' => 0,
                'default' => 0,
                'range' => [
                    'lower' => mktime(0, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y'))
                ],
            ],
        ],
        'name' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.name',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true
            ],
        ],
        'model' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.model',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true
            ],
        ],
        'system_message' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.system_message',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 6
            ],
        ],
        'url' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.url',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true
            ],
        ],
        'api_key' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.api_key',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true
            ]
        ]
    ],
];
