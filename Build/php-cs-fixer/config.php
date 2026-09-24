<?php

declare(strict_types=1);

use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;
use TYPO3\CodingStandards\CsFixerConfig;

$config = CsFixerConfig::create();
$config->setParallelConfig(ParallelConfigFactory::detect());

// Classes/Domain/Model/Gen is generated from Schema/openai.models.yml by generate-code.sh
$config->getFinder()
    ->in(['Build', 'Classes', 'Configuration', 'Tests'])
    ->exclude(['Domain/Model/Gen', 'build', '.phpunit.cache'])
    ->append(array_map('realpath', glob(__DIR__ . '/../../*.php') ?: []));

return $config;
