<?php

declare(strict_types=1);
if (!defined('TYPO3')) {
    die('Access denied.');
}

$lll = 'LLL:EXT:easychat/Resources/Private/Language/locallang_db.xlf:tx_easychat_index_point';

/**
 * Read-only view on IndexPointRegistry's bookkeeping, so that admins can see in the List
 * module (root page) which vector store points exist and where they came from. Written only
 * by IndexEventListener.
 */
return [
    'ctrl' => [
        'title' => $lll,
        'label' => 'point_id',
        'label_alt' => 'kind,page_uid,language',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'default_sortby' => 'tstamp DESC',
        'rootLevel' => 1,
        'adminOnly' => true,
        'iconfile' => 'EXT:easychat/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '0' => ['showitem' => 'configuration,index_configuration,kind,page_uid,language,--linebreak--,point_id,document_id,index_process,tstamp'],
    ],
    'columns' => [
        'configuration' => [
            'label' => $lll . '.configuration',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'index_configuration' => [
            'label' => $lll . '.index_configuration',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'kind' => [
            'label' => $lll . '.kind',
            'config' => ['type' => 'input', 'readOnly' => true, 'searchable' => false],
        ],
        'page_uid' => [
            'label' => $lll . '.page_uid',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'language' => [
            'label' => $lll . '.language',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'point_id' => [
            'label' => $lll . '.point_id',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'document_id' => [
            'label' => $lll . '.document_id',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'index_process' => [
            'label' => $lll . '.index_process',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'tstamp' => [
            'label' => $lll . '.tstamp',
            'config' => ['type' => 'datetime', 'readOnly' => true, 'searchable' => false],
        ],
    ],
];
