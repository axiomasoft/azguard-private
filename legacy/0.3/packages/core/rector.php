<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
    ])

    // Target: PHP 8.3 — enables readonly properties, typed class constants,
    // first-class callable syntax, fibers, etc.
    ->withPhpSets(php83: true)

    // Code quality: removes dead code, simplifies conditions, modernises syntax.
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
        // Uncomment after: composer require --dev driftingly/rector-laravel
        // \RectorLaravel\Set\LaravelSetList::LARAVEL_110,
    ])

    // Preserve Eloquent magic and Laravel container calls — skip model internals.
    ->withSkip([
        RemoveUnusedPrivateMethodRector::class,
        AddVoidReturnTypeWhereNoReturnRector::class => [
            // Eloquent boot* hooks must not have a void return type annotation
            // because Laravel checks method_exists and then calls them dynamically.
            __DIR__.'/src/Concerns',
        ],
    ])

    ->withImportNames(removeUnusedImports: true);
