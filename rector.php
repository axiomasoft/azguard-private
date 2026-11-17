<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Packman\DevTools\Rector\Rules\Boundaries\BoundarySafeStringClassRector;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\FuncCall\SimplifyRegexPatternRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\DeadCode\Rector\Return_\RemoveDeadConditionAboveReturnRector;
use Rector\Instanceof_\Rector\Ternary\FlipNegatedTernaryInstanceofRector;
use Rector\Php80\Rector\Ternary\TernaryToNullsafeCoalesceRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;

return static function (RectorConfig $rectorConfig): void {
    $skipped = class_exists(TernaryToNullsafeCoalesceRector::class) ? [TernaryToNullsafeCoalesceRector::class] : [];

    if (class_exists(ExplicitNullableObjectInstanceRector::class)) {
        $skipped[] = ExplicitNullableObjectInstanceRector::class;
    }

    $rectorConfig->paths([
        __DIR__.'/packages/core/src',
        __DIR__.'/packages/scopes/src',
        __DIR__.'/packages/audit/src',
        __DIR__.'/packages/devtools/src',
        __DIR__.'/packages/filament/src',
        __DIR__.'/bench/src',
    ]);
    $rectorConfig->importNames(importDocBlockNames: false);

    PackmanRectorSet::configure($rectorConfig, [
        ...$skipped,
        RemoveEmptyClassMethodRector::class => [
            __DIR__.'/packages/core/src/AzGuardServiceProvider.php',
        ],
        SimplifyRegexPatternRector::class,
        ThrowWithPreviousExceptionRector::class,
        FlipTypeControlToUseExclusiveTypeRector::class,
        FlipNegatedTernaryInstanceofRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        RemoveUnusedPublicMethodParameterRector::class,
        ReadOnlyClassRector::class,
        NarrowObjectReturnTypeRector::class,
        RemoveNonExistingVarAnnotationRector::class,
        RemoveDeadConditionAboveReturnRector::class => [
            __DIR__.'/packages/core/src/Authorization/Internal/Query/PredicateBuilder.php',
        ],
        RemoveUselessParamTagRector::class => [
            __DIR__.'/packages/core/src/Storage/Internal/Concerns/GuardsDirectWrites.php',
            __DIR__.'/packages/core/src/Storage/Internal/WriteGuardedBuilder.php',
        ],
        BoundarySafeStringClassRector::class => [
            __DIR__.'/packages/core/src/Configuration/AzGuardOptions.php',
            __DIR__.'/packages/core/src/Configuration/Internal/AzGuardConfig.php',
        ],
        ReadOnlyPropertyRector::class => [
            __DIR__.'/packages/core/src/Authorization/Internal/Query/PredicateQuery.php',
        ],
    ]);
};
