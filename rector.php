<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\FuncCall\SimplifyRegexPatternRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/packages/core/src',
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
        // Provider skeletons keep empty register()/boot() as the registration points later items fill in.
        RemoveEmptyClassMethodRector::class => [
            __DIR__.'/packages/core/src/AzGuardServiceProvider.php',
            __DIR__.'/packages/filament/src/AzGuardFilamentServiceProvider.php',
        ],
        // Keep identifier/permission grammar regex explicit; `\w` is not a documented contract.
        SimplifyRegexPatternRector::class,
    ]);
