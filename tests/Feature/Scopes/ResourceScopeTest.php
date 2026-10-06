<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Scopes\Project;
use AzGuard\Tests\Fixtures\Scopes\ScopedResource;
use AzGuard\Tests\Fixtures\Scopes\ScopeSource;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

afterEach(fn () => Relation::morphMap([], false));

it('selects explicit then resource then current scope without leaking another panel', function (): void {
    $source = new ScopeSource(direct: [ScopeWorld::grant(ScopeWorld::scope(context: 1)), ScopeWorld::grant(ScopeWorld::scope('B', 2))]);
    [$engine, $panel, $request] = ScopeWorld::compile($source);
    $current = app(CurrentContext::class);
    $current->set($panel, ScopeWorld::scope(context: 1));

    expect($engine->decide($panel, $request)->scope->equals(ScopeWorld::scope(context: 1)))->toBeTrue();
    $resource = new ScopedResource(ScopeWorld::scope('B', 2));
    $resourceRequest = $request->on(null, $resource);
    expect($engine->decide($panel, $resourceRequest)->reason)->toBe(DecisionReason::TenantMismatch);

    $current->set($panel, null);
    $decision = $engine->decide($panel, $resourceRequest);
    expect($decision->allowed())->toBeTrue()->and($decision->scope->equals(ScopeWorld::scope('B', 2)))->toBeTrue();
    $current->set($panel, ScopeWorld::scope('B', 2));
    $decision = $engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)));
    expect($decision->allowed())->toBeTrue()->and($decision->scope->equals(ScopeWorld::scope(context: 1)))->toBeTrue();
});

it('requires tenant and authoritative resource owner before policy true or admin authority', function (string $authority, string $case, DecisionReason $reason): void {
    $source = new GeneratedSource(direct: [ScopeWorld::grant(ScopeWorld::scope())], roles: [RoleContribution::of(RoleKey::of('admin', 'root'), ScopeWorld::scope(), 'generated')]);
    [$engine, $panel, $request] = ScopeWorld::compile($source, configure: fn (PanelBuilder $panel) => $panel->roles([GrantableRootRole::class])->policies([PolicyBinding::for('orders.view', RuntimePolicy::class)]));
    $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', $authority === 'policy' ? 'orders.policy' : 'orders.view'));
    $request = match ($case) {
        'tenant' => $request,
        'owner' => $request->inTenant(TenantRef::of('org', 'A'))->on(null, new Project),
        'mismatch' => $request->inTenant(TenantRef::of('org', 'A'))->on(null, new ScopedResource(ScopeWorld::scope('B', 2))),
        'context' => $request->inScope(ScopeWorld::scope(context: 1), new ScopedResource(ScopeWorld::scope())),
    };

    expect($engine->decide($panel, $request)->reason)->toBe($reason)->and(RuntimePolicy::$calls)->toBe(0)->and($source->grantReads)->toBe(0)->and($source->roleReads)->toBe(0);
})->with(['policy', 'admin'])->with([
    ['tenant', DecisionReason::TenantRequired], ['owner', DecisionReason::ResourceScopeMissing],
    ['mismatch', DecisionReason::TenantMismatch], ['context', DecisionReason::AssignmentScopeMismatch],
]);

it('passes selected scope to an authoritative resource resolver', function (): void {
    $resolver = new class implements ResourceScopeResolver
    {
        public ?AccessScope $selected = null;

        public function resolve(object $resource, ?AccessScope $selected = null): AccessScope
        {
            $this->selected = $selected;

            return ScopeWorld::scope(context: 1);
        }
    };
    [$engine, $panel, $request] = ScopeWorld::compile(new ScopeSource(direct: [ScopeWorld::grant(ScopeWorld::scope())]), configure: fn (PanelBuilder $panel) => $panel->resourceScopes([Project::class => $resolver]));
    $decision = $engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1), new Project));
    expect($decision->allowed())->toBeTrue()->and($resolver->selected?->equals(ScopeWorld::scope(context: 1)))->toBeTrue();
});

it('rejects a resource resolver returning a foreign tenant or context before policy true', function (bool $foreignTenant): void {
    $resolver = new class($foreignTenant) implements ResourceScopeResolver
    {
        public function __construct(private readonly bool $foreignTenant) {}

        public function resolve(object $resource, ?AccessScope $selected = null): AccessScope
        {
            return $this->foreignTenant ? ScopeWorld::scope('B', 2) : ScopeWorld::scope();
        }
    };
    [$engine, $panel, $request] = ScopeWorld::compile(new ScopeSource, configure: fn (PanelBuilder $panel) => $panel->resourceScopes([Project::class => $resolver]));
    $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'))->inScope(ScopeWorld::scope(context: 1), new Project);
    expect($engine->decide($panel, $request)->reason)->toBe($foreignTenant ? DecisionReason::TenantMismatch : DecisionReason::AssignmentScopeMismatch)->and(RuntimePolicy::$calls)->toBe(0);
})->with([false, true]);

it('denies missing exceptional or forged structural resolver snapshots', function (string $case, DecisionReason $reason): void {
    $definition = new StoreScope(function (AssignmentScopeRef $ref) use ($case): ?ResolvedAssignmentScope {
        if ($case === 'throw') {
            throw new RuntimeException('structural resolver unavailable');
        }

        if ($case === 'missing') {
            return null;
        }

        if ($case === 'record') {
            $record = new Project;
            $record->setAttribute('id', 2);

            return new ResolvedAssignmentScope($ref, TenantRef::of('org', 'A'), $record);
        }

        return new ResolvedAssignmentScope($case === 'ref' ? AssignmentScopeRef::of('store', 2) : $ref, TenantRef::of('org', $case === 'tenant' ? 'B' : 'A'));
    });
    [$engine, $panel, $request] = ScopeWorld::compile(new ScopeSource(direct: [ScopeWorld::grant(ScopeWorld::scope())]), definition: $definition);
    expect($engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)))->reason)->toBe($reason);
})->with([
    ['missing', DecisionReason::AssignmentScopeNotAccepted], ['throw', DecisionReason::AssignmentScopeFilterError],
    ['ref', DecisionReason::AssignmentScopeMismatch], ['tenant', DecisionReason::AssignmentScopeMismatch], ['record', DecisionReason::AssignmentScopeMismatch],
]);

it('accepts an external model-null structural definition without invoking query', function (): void {
    $definition = new StoreScope;
    [$engine, $panel, $request] = ScopeWorld::compile(new ScopeSource(direct: [ScopeWorld::grant(ScopeWorld::scope())]), definition: $definition);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $decision = $engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)));
    $scopeQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'projects') || str_contains($query['query'], 'stores'));
    DB::disableQueryLog();
    expect($definition->model())->toBeNull()->and($decision->allowed())->toBeTrue()->and($definition->resolves)->toBe(1)->and($scopeQueries)->toBe([]);
});
