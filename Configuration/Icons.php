<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'tx-easychat-extension' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:easychat/Resources/Public/Icons/Extension.svg',
    ],
];
