<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\FuncCall\SimplifyRegexPatternRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/packages/core/src',
        __DIR__.'/packages/context/src',
        __DIR__.'/packages/filament/src',
    ])
    ->withPhpSets(php83: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
    ])
    ->withImportNames()
    ->withSkip([
        __DIR__.'/packages/core/src/Roles/BaseRole.php',
        // Keep identifier/permission grammar regex explicit; `\w` is not a documented contract.
        SimplifyRegexPatternRector::class,
    ]);
