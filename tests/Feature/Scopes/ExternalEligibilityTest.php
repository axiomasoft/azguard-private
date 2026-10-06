<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Scopes\EligibilityExternalAdapter;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(fn () => Relation::morphMap([], false));

it('runs external scalar eligibility after structural resolution without a query model', function (bool $allowed): void {
    $adapter = new EligibilityExternalAdapter(static fn (): bool => $allowed);
    $definition = new StoreScope;
    $source = new GeneratedSource(direct: [ScopeWorld::grant(ScopeWorld::scope(context: 1))]);
    [$engine, $panel, $request] = ScopeWorld::compile($source, definition: $definition, configure: fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit($definition)->accessAdapter('store', $adapter)));
    $decision = $engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)), ActorRef::system('worker'));
    expect($decision->allowed())->toBe($allowed)->and($definition->resolves)->toBe(1)->and($adapter->observed)->toHaveCount($allowed ? 2 : 1);
    $common = $adapter->observed[0];
    expect($common->role)->toBeNull()->and($common->grant)->toBeNull()->and($common->phase)->toBe(AssignmentScopePhase::Access)
        ->and($common->subject->id())->toBe('1')->and($common->user?->getKey())->toBe(1)->and($common->actorModel)->toBeNull();

    if (! $allowed) {
        expect($decision->reason)->toBe(DecisionReason::AssignmentScopeIneligible)->and($source->grantReads)->toBe(0);
    } else {
        expect($adapter->observed[1]->grant)->toBe($source->direct[0]);
    }
})->with([true, false]);

it('does not call external eligibility for unknown foreign owner or foreign identity', function (string $case): void {
    $adapter = new EligibilityExternalAdapter;
    $definition = new StoreScope;
    $source = new GeneratedSource;
    [$engine, $panel, $request] = ScopeWorld::compile($source, definition: $definition, configure: fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit($definition)->accessAdapter('store', $adapter)));
    $scope = match ($case) {
        'owner' => ScopeWorld::scope(context: 2),
        'missing' => AccessScope::in(ScopeWorld::scope()->tenant, AssignmentScopeRef::of('store', 99)),
        default => AccessScope::in(ScopeWorld::scope()->tenant, AssignmentScopeRef::of('foreign', 1)),
    };
    expect($engine->decide($panel, $request->inScope($scope))->allowed())->toBeFalse()->and($adapter->observed)->toBe([]);
})->with(['owner', 'missing', 'foreign']);

it('fails closed on external exceptions without broad query fallback', function (): void {
    $adapter = new EligibilityExternalAdapter(static function (): bool {
        throw new RuntimeException('remote refused');
    });
    $definition = new StoreScope;
    [$engine, $panel, $request] = ScopeWorld::compile(new GeneratedSource, definition: $definition, configure: fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit($definition)->accessAdapter('store', $adapter)));
    expect($engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)))->reason)->toBe(DecisionReason::AssignmentScopeFilterError);
});

it('resolves the external adapter class at each operation with live inputs', function (): void {
    $created = 0;
    app()->bind(EligibilityExternalAdapter::class, function () use (&$created): EligibilityExternalAdapter {
        $created++;

        return new EligibilityExternalAdapter(static fn ($ref, AssignmentScopeRuntime $runtime): bool => $runtime->subject->id() === '1');
    });
    $definition = new StoreScope;
    $source = new GeneratedSource(direct: [ScopeWorld::grant(ScopeWorld::scope(context: 1))]);
    [$engine, $panel, $request] = ScopeWorld::compile($source, definition: $definition, configure: fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit($definition)->accessAdapter('store', EligibilityExternalAdapter::class)));
    expect($created)->toBe(0);
    for ($i = 0; $i < 2; $i++) {
        expect($engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)))->allowed())->toBeTrue();
    }
    expect($created)->toBe(4);
});
