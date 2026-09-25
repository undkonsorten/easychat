<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

if (!defined('TYPO3')) {
    die('Access denied.');
}

$tca = [
    'ctrl' => [
        'title'	=> 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration',
        'label' => 'name',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'versioningWS' => false,
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'iconfile' => 'EXT:easychat/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '1' => ['showitem' => 'sys_language_uid,l10n_parent,l10n_diffsource,hidden,--palette--;;1,name,-div--;palette,
                    --palette--;;llm,-div--;palette,--palette--;;vector,--div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.access,starttime,endtime'],
    ],
    'palettes' => [
        'llm' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.palette.llm.label',
            'description' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.palette.llm.description',
            'showitem' => 'url,api_key,--linebreak--,model,--linebreak--,system_message',
        ],
        'vector' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.palette.vector.label',
            'description' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.palette.vector.description',
            'showitem' => 'vector_db_host,vector_db,vector_db_port,vector_db_name,--linebreak--,vector_db_api_key,vector_db_dimensions,--linebreak--,vector_db_embeddings_model,--linebreak--,vector_db_embeddings_url,vector_db_embeddings_api_key,--linebreak--,index_configurations,--linebreak--,vector_db_sync_removals,vector_db_sync_removals_threshold',
        ],
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
                'type' => 'datetime',
                'size' => 13,
                'checkbox' => 0,
                'default' => 0,
                'range' => [
                    'lower' => mktime(0, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y')),
                ],
                'searchable' => false,
            ],
        ],
        'endtime' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.endtime',
            'config' => [
                'type' => 'datetime',
                'size' => 13,
                'checkbox' => 0,
                'default' => 0,
                'range' => [
                    'lower' => mktime(0, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y')),
                ],
                'searchable' => false,
            ],
        ],
        'name' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.name',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'model' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.model',
            'description' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.model.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'eval' => 'trim',
                'required' => true,
                'valuePicker' => [
                    'items' => [],
                ],
            ],
        ],
        'system_message' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.system_message',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 6,
                'searchable' => false,
            ],
        ],
        'url' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.url',
            'onChange' => 'reload',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'api_key' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.api_key',
            'onChange' => 'reload',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true,
                'searchable' => false,
            ],
        ],
        'vector_db' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    [
                        'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db.none',
                        'value' => 'none',
                    ],
                    [
                        'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db.redis',
                        'value' => 'redis',
                    ],
                    [
                        'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db.qdrant',
                        'value' => 'qdrant',
                    ],
                ],
            ],
        ],
        'vector_db_host' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_host',
            'displayCond' => [
                'AND' => [
                    'FIELD:vector_db:!=:none',
                    'REC:NEW:false',
                ],
            ],
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true,
                'searchable' => false,
            ],
        ],
        'vector_db_port' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_port',
            'displayCond' => [
                'AND' => [
                    'FIELD:vector_db:!=:none',
                    'REC:NEW:false',
                ],
            ],
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true,
                'searchable' => false,
            ],
        ],
        'vector_db_name' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_name',
            'displayCond' => [
                'AND' => [
                    'FIELD:vector_db:!=:none',
                    'REC:NEW:false',
                ],
            ],
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true,
                'searchable' => false,
            ],
        ],
        'vector_db_api_key' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_api_key',
            'displayCond' => [
                'AND' => [
                    'FIELD:vector_db:!=:none',
                    'REC:NEW:false',
                    'OR' => [
                        'FIELD:vector_db:=:qdrant',
                    ],
                ],
            ],
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true,
                'searchable' => false,
            ],
        ],
        'vector_db_embeddings_model' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_embeddings_model',
            'description' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_embeddings_model.description',
            'displayCond' => [
                'AND' => [
                    'FIELD:vector_db:!=:none',
                    'REC:NEW:false',
                    'OR' => [
                        'FIELD:vector_db:=:qdrant',
                    ],
                ],
            ],
            'config' => [
                'type' => 'input',
                'size' => 50,
                'eval' => 'trim',
                'required' => true,
                'valuePicker' => [
                    'items' => [],
                ],
                'searchable' => false,
            ],
        ],
        'vector_db_embeddings_url' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_embeddings_url',
            'description' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_embeddings_url.description',
            'onChange' => 'reload',
            'displayCond' => [
                'AND' => [
                    'FIELD:vector_db:!=:none',
                    'REC:NEW:false',
                    'OR' => [
                        'FIELD:vector_db:=:qdrant',
                    ],
                ],
            ],
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'searchable' => false,
            ],
        ],
        'vector_db_embeddings_api_key' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_embeddings_api_key',
            'description' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_embeddings_api_key.description',
            'onChange' => 'reload',
            'displayCond' => [
                'AND' => [
                    'FIELD:vector_db:!=:none',
                    'REC:NEW:false',
                    'OR' => [
                        'FIELD:vector_db:=:qdrant',
                    ],
                ],
            ],
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'searchable' => false,
            ],
        ],
        'vector_db_dimensions' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_dimensions',
            'displayCond' => [
                'AND' => [
                    'FIELD:vector_db:!=:none',
                    'REC:NEW:false',
                    'OR' => [
                        'FIELD:vector_db:=:qdrant',
                    ],
                ],
            ],
            'config' => [
                'type' => 'number',
                'default' => 1536,
                'required' => true,
            ],
        ],
        'index_configurations' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.index_configurations',
            'description' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.index_configurations.description',
            'displayCond' => [
                'AND' => [
                    'FIELD:vector_db:!=:none',
                    'REC:NEW:false',
                    'OR' => [
                        'FIELD:vector_db:=:qdrant',
                    ],
                ],
            ],
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_index_domain_model_configuration',
                'size' => 5,
                'maxitems' => 99,
            ],
        ],
        'vector_db_sync_removals' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_sync_removals',
            'description' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_sync_removals.description',
            'displayCond' => [
                'AND' => [
                    'FIELD:vector_db:!=:none',
                    'REC:NEW:false',
                    'OR' => [
                        'FIELD:vector_db:=:qdrant',
                    ],
                ],
            ],
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
            'onChange' => 'reload',
        ],
        'vector_db_sync_removals_threshold' => [
            'label' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_sync_removals_threshold',
            'description' => 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_configuration.vector_db_sync_removals_threshold.description',
            'displayCond' => 'FIELD:vector_db_sync_removals:=:1',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'default' => 25,
                'range' => [
                    'lower' => 1,
                    'upper' => 100,
                ],
            ],
        ],
    ],
];

// TYPO3 v14 searches all suitable fields unless a column sets 'searchable' => false, and removed
// ctrl.searchFields. TYPO3 v13 still needs the explicit list.
if ((new Typo3Version())->getMajorVersion() < 14) {
    $tca['ctrl']['searchFields'] = 'name,model,url';
}

return $tca;
