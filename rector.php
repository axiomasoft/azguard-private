<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\FuncCall\SimplifyRegexPatternRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\Instanceof_\Rector\Ternary\FlipNegatedTernaryInstanceofRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php80\Rector\Ternary\TernaryToNullsafeCoalesceRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
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
    // Docblock-only references stay fully qualified: an import would be a real `use` that the zone arch rules
    // count as a dependency (Facades → Testing, SchemaBuilder → Catalog).
    ->withImportNames(importDocBlockNames: false)
    ->withSkip([
        // Provider skeletons keep empty register()/boot() as the registration points later items fill in.
        RemoveEmptyClassMethodRector::class => [
            __DIR__.'/packages/core/src/AzGuardServiceProvider.php',
            __DIR__.'/packages/filament/src/AzGuardFilamentServiceProvider.php',
        ],
        // Keep identifier/permission grammar regex explicit; `\w` is not a documented contract.
        SimplifyRegexPatternRector::class,
        // DefinitionException::code() is the machine code. Copying Throwable::getCode() into the int code of
        // RuntimeException changes the thrown code and can receive a string — everywhere, not only in two files.
        ThrowWithPreviousExceptionRector::class,
        // `$x === null` reads as the intent for a nullable value; PHPStan level 8 already proves the type, so
        // `! $x instanceof Foo` adds churn (87 files with Rector 2.7) and no safety.
        FlipTypeControlToUseExclusiveTypeRector::class,
        FlipNegatedTernaryInstanceofRector::class,
        // Private static helpers are deliberate: they cannot touch instance state. Converting them is churn.
        LocallyCalledStaticMethodToNonStaticRector::class,
        // A public method parameter is a contract (a change pipe receives $next even when it cancels).
        RemoveUnusedPublicMethodParameterRector::class,
        // `readonly class` is a public API change recorded in api-manifest.json; promote classes deliberately.
        ReadOnlyClassRector::class,
        // `$a === null ? [] : $a->b` → `$a?->b ?? []` hides which side may be null; PHPStan then reports
        // nullsafe.neverNull on non-nullable properties.
        TernaryToNullsafeCoalesceRector::class,
        // Drops `@var class-string` narrowing that PHPStan needs on a string built from a regex match.
        RemoveNonExistingVarAnnotationRector::class,
        // Configuration names default storage models as strings on purpose: the zone rule forbids it to depend on Storage.
        StringClassNameToClassConstantRector::class => [__DIR__.'/packages/core/src/Configuration/AzGuardConfig.php'],
        // PredicateQuery is cloned and re-bound to a new guard; readonly breaks the clone (NativeBuilderTest).
        ReadOnlyPropertyRector::class => [__DIR__.'/packages/core/src/Scopes/Query/PredicateQuery.php'],
    ]);
