<?php

declare(strict_types=1);

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Pipeline\Stages\AuthorityStage;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Scopes\ScopeSource;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use AzGuard\Tests\Fixtures\Scopes\TenantDynamicSource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

afterEach(fn () => Relation::morphMap([], false));

it('implements every scope table cell for direct and role authority', function (string $mode, bool $context, ?DecisionReason $denial, bool $role): void {
    $selected = ScopeWorld::scope(context: $context ? 1 : null);
    $wide = ScopeWorld::grant(ScopeWorld::scope(), $role);
    $local = ScopeWorld::grant(ScopeWorld::scope(context: 1), $role);
    $source = new ScopeSource(direct: $role ? [] : [$wide, $local], roles: $role ? [$wide, $local] : []);
    [$engine, $panel, $request] = ScopeWorld::compile($source, $mode);
    $decision = $engine->decide($panel, $request->inScope($selected)->traced());

    expect($decision->reason)->toBe($denial ?? DecisionReason::Granted);

    if ($denial !== null) {
        expect($source->grantReads)->toBe(0)->and($source->roleReads)->toBe(0);

        return;
    }

    $expected = $context && $mode !== 'isolated' ? [ScopeWorld::scope(), $selected] : [$selected];
    $keys = static fn (array $scopes): array => array_map(static fn (AccessScope $scope): string => $scope->tenant->key().'/'.$scope->context->key(), $scopes);
    expect($keys($source->directScopes))->toBe($keys($expected))->and($keys($source->roleScopes))->toBe($keys($expected));

    if (! $role) {
        expect($decision->grants)->toBe($context ? ($mode === 'isolated' ? [$local] : [$wide, $local]) : [$wide]);
    }
})->with([
    ['inherit', false, null], ['inherit', true, null],
    ['isolated', false, null], ['isolated', true, null],
    ['required', false, DecisionReason::AssignmentScopeRequired], ['required', true, null],
    ['none', false, null], ['none', true, DecisionReason::AssignmentScopeNotAccepted],
])->with([false, true]);

it('does not broaden an isolated contextual check with tenant-wide authority', function (bool $role): void {
    $grant = ScopeWorld::grant(ScopeWorld::scope(), $role);
    [$engine, $panel, $request] = ScopeWorld::compile(new ScopeSource(direct: $role ? [] : [$grant], roles: $role ? [$grant] : []), 'isolated');
    expect($engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)))->reason)->toBe(DecisionReason::NotGranted);
})->with([false, true]);

it('requires one complete scoped contribution instead of combining conditions across scopes', function (): void {
    $wide = ScopeWorld::grant(ScopeWorld::scope());
    $local = ScopeWorld::grant(ScopeWorld::scope(context: 1));
    $source = new ScopeSource(direct: [$wide, $local]);
    $first = new class implements GrantCondition
    {
        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            return $grant->scope->context->isGlobal();
        }
    };
    $second = new class implements GrantCondition
    {
        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            return ! $grant->scope->context->isGlobal();
        }
    };
    [$engine, $panel, $request] = ScopeWorld::compile($source, configure: fn (PanelBuilder $panel) => $panel->grantConditions([$first, $second]));
    expect($engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)))->reason)->toBe(DecisionReason::NotGranted);
});

it('rejects a malicious global or foreign contribution even after a valid witness', function (string $tenant, bool $role): void {
    $badScope = $tenant === 'global' ? AccessScope::in(TenantRef::global()) : ScopeWorld::scope($tenant);
    $valid = ScopeWorld::grant(ScopeWorld::scope(), $role);
    $bad = ScopeWorld::grant($badScope, $role);
    [$engine, $panel, $request] = ScopeWorld::compile(new GeneratedSource(direct: $role ? [] : [$valid, $bad], roles: $role ? [$valid, $bad] : []));
    expect($engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)))->reason)->toBe(DecisionReason::SourceError);
})->with(['global', 'B'])->with([false, true]);

it('passes the same accepted scopes to coherent and fallback authority reads', function (bool $coherent, bool $role): void {
    $wide = ScopeWorld::grant(ScopeWorld::scope(), $role);
    $source = new ScopeSource(direct: $role ? [] : [$wide], roles: $role ? [$wide] : []);
    [$engine, $panel, $request] = ScopeWorld::compile($source);
    $request = $request->inScope(ScopeWorld::scope(context: 1));

    if ($coherent) {
        $decision = $engine->decide($panel, $request);
    } else {
        $catalog = app(PanelRegistry::class)->catalog('admin');
        $frame = new EvaluationFrame($panel, ScopeWorld::scope(context: 1), CodeStateToken::of('admin', 'test', 'test'), Carbon::now()->toDateTimeImmutable(), ActorRef::system());
        [, $decision] = app(AuthorityStage::class)->decide($request, $frame, $catalog, $catalog->find($request->permission()), new Trace);
    }

    expect($decision->allowed())->toBeTrue()->and($source->directScopes)->toHaveCount(2)->and($source->roleScopes)->toHaveCount(2);
})->with([false, true])->with([false, true]);

it('resolves the selected tenant before reading dynamic permission definitions', function (): void {
    $scope = ScopeWorld::scope(context: 1);
    $source = new TenantDynamicSource(name: 'tenant-dynamic', direct: [Grant::of(PermissionPattern::of('admin', 'orders.dynamic'), 'tenant-dynamic', ScopeWorld::scope())]);
    [$engine, $panel, $request] = ScopeWorld::compile(new ScopeSource, configure: fn (PanelBuilder $panel) => $panel->permissions([$source]));
    $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.dynamic'))->inScope($scope);
    expect($engine->decide($panel, $request)->allowed())->toBeTrue()->and($source->observedTenant?->equals($scope->tenant))->toBeTrue()->and($source->observedScopes)->toHaveCount(2);
});

it('gives every source the same complete tenant and context selection', function (): void {
    $first = new ScopeSource(direct: [ScopeWorld::grant(ScopeWorld::scope())]);
    $second = new ScopeSource(name: 'second', direct: [ScopeWorld::grant(ScopeWorld::scope(context: 1))]);
    [$engine, $panel, $request] = ScopeWorld::compile($first, configure: fn (PanelBuilder $panel) => $panel->permissions([$second]));
    expect($engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)))->allowed())->toBeTrue()
        ->and($first->directScopes)->toEqual($second->directScopes)->and($first->roleScopes)->toEqual($second->roleScopes)->and($first->directScopes)->toHaveCount(2);
});
