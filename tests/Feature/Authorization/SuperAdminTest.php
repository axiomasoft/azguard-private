<?php

declare(strict_types=1);

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Pipeline\Stages\AuthorityStage;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\FailingSource;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Authorization\WeekdaysCondition;
use AzGuard\Tests\Fixtures\Roles\TenantAdminRole;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\ScopedResource;
use AzGuard\Tests\Fixtures\Scopes\ScopeSource;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

it('implements the tenant and contextual superadmin assignment table', function (string $mode, ?int $assigned, string $tenant, ?int $selected, bool $allowed): void {
    $contribution = RoleContribution::of(RoleKey::of('admin', 'tenant-admin'), ScopeWorld::scope(context: $assigned), 'generated');
    $definition = new StoreScope(fn (AssignmentScopeRef $ref): ResolvedAssignmentScope => new ResolvedAssignmentScope($ref, TenantRef::of('org', $tenant)));
    app()->instance(StoreScope::class, $definition);
    [$engine, $panel, $request] = ScopeWorld::compile(new ScopeSource(roles: [$contribution]), $mode, fn (PanelBuilder $panel) => $panel->roles([TenantAdminRole::class]), definition: $definition);
    $scope = ScopeWorld::scope($tenant, $selected);
    $decision = $engine->decide($panel, $request->inScope($scope));
    expect($decision->allowed())->toBe($allowed)
        ->and($decision->reason)->toBe($allowed ? DecisionReason::SuperAdmin : DecisionReason::NotGranted)
        ->and($engine->isSuperAdmin($panel, $request->subject(), $scope))->toBe($allowed);
})->with([
    ['inherit', null, 'A', null, true], ['inherit', null, 'A', 1, true],
    ['isolated', null, 'A', 1, false], ['inherit', 1, 'A', 1, true],
    ['inherit', 1, 'A', 2, false], ['inherit', 1, 'A', null, false],
    ['inherit', null, 'B', null, false], ['inherit', 1, 'B', 1, false],
]);

it('empty permissions superadmin covers its non tenant panel and reports a qualified empty witness', function (): void {
    $scope = AccessScope::in(TenantRef::global());
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), $scope, 'generated')]));
    $decision = $engine->decide($panel, $request->traced());
    $trace = new Trace(true);
    $frame = new EvaluationFrame($panel, $scope, CodeStateToken::of('admin', 'test', 'test'), Carbon::now()->toDateTimeImmutable(), ActorRef::system());
    app(AuthorityStage::class)->qualify($request, $frame, app(PanelRegistry::class)->catalog('admin'), $trace);
    expect($decision->reason)->toBe(DecisionReason::SuperAdmin)
        ->and($engine->isSuperAdmin($panel, $request->subject(), $scope))->toBeTrue()
        ->and(json_encode($trace->steps()))->toContain('qualified_empty_super_admin');
});

it('global superadmin enters required tenants only through its exact allow listed class', function (bool $listed): void {
    $global = AccessScope::in(TenantRef::global());
    $source = new ScopeSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), $global, 'generated')]);
    [$engine, $panel, $request] = ScopeWorld::compile($source, configure: function (PanelBuilder $panel) use ($listed): void {
        $panel->roles([GrantableRootRole::class, TenantAdminRole::class])
            ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership)->allowGlobalRoles($listed ? [GrantableRootRole::class] : [TenantAdminRole::class]));
    });
    foreach (['A', 'B'] as $tenant) {
        $scope = ScopeWorld::scope($tenant);
        expect($engine->decide($panel, $request->inScope($scope))->allowed())->toBe($listed)
            ->and($engine->isSuperAdmin($panel, $request->subject(), $scope))->toBe($listed);
    }
})->with([false, true]);

it('global superadmin cannot cross resource owner tenant or context', function (bool $tenantMismatch): void {
    $source = new ScopeSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated')]);
    [$engine, $panel, $request] = ScopeWorld::compile($source, configure: fn (PanelBuilder $panel) => $panel->roles([GrantableRootRole::class])->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership)->allowGlobalRoles([GrantableRootRole::class])));
    $owner = $tenantMismatch ? ScopeWorld::scope('B', 1) : ScopeWorld::scope(context: 2);
    expect($engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1), new ScopedResource($owner)))->reason)
        ->toBe($tenantMismatch ? DecisionReason::TenantMismatch : DecisionReason::AssignmentScopeMismatch)
        ->and($source->roleReads)->toBe(0);
})->with([false, true]);

it('qualifies isSuperAdmin without before policies or restrictions and still enforces their access veto', function (string $veto): void {
    $hooks = 0;
    $restriction = new RecordingRestriction(deny: $veto === 'restriction');
    RuntimePolicy::$result = $veto !== 'policy';
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated')]), function (PanelBuilder $panel) use (&$hooks, $restriction, $veto): void {
        $panel->before(function () use (&$hooks, $veto): BeforeResult {
            $hooks++;

            return $veto === 'before' ? BeforeResult::Deny : BeforeResult::Continue;
        })->policies([PolicyBinding::for('orders.view', RuntimePolicy::class)])->restrictions([$restriction]);
    });
    expect($engine->isSuperAdmin($panel, $request->subject(), AccessScope::in(TenantRef::global())))->toBeTrue()
        ->and($hooks)->toBe(0)->and(RuntimePolicy::$calls)->toBe(0)->and($restriction->checks)->toBe(0);
    expect($engine->decide($panel, $request)->allowed())->toBeFalse();
})->with(['before', 'policy', 'restriction']);

it('checks the same expiry and contribution conditions for admin qualification and access', function (string $case, bool $allowed): void {
    $admin = RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated', expiresAt: $case === 'expired' ? Carbon::now()->toDateTimeImmutable() : Carbon::now()->addSecond()->toDateTimeImmutable(), fields: ['weekdays' => [$case === 'condition' ? 2 : 1]]);
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(roles: [$admin]), fn (PanelBuilder $panel) => $panel->grantConditions([WeekdaysCondition::class]));
    expect($engine->isSuperAdmin($panel, $request->subject(), AccessScope::in(TenantRef::global())))->toBe($allowed)
        ->and($engine->decide($panel, $request)->allowed())->toBe($allowed);
})->with([['valid', true], ['expired', false], ['condition', false]]);

it('keeps restriction exemption monotonic with ordinary grants in either source order', function (bool $ordinary, bool $reverse, bool $exempt): void {
    $admin = new GeneratedSource(name: 'admin-source', roles: [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'admin-source')]);
    $other = new GeneratedSource(name: 'ordinary-source', direct: $ordinary ? [AuthorizationWorld::grant()] : []);
    $restriction = new RecordingRestriction(deny: true, exempt: $exempt);
    [$engine, $panel, $request] = AuthorizationWorld::compile($reverse ? $other : $admin, fn (PanelBuilder $panel) => $panel->restrictions([$restriction]), extra: [$reverse ? $admin : $other]);
    expect($engine->decide($panel, $request)->allowed())->toBe($exempt)->and($restriction->checks)->toBe($exempt ? 0 : 1);
})->with([false, true])->with([false, true])->with([false, true]);

it('never exempts PolicyOnly because the subject also holds an admin role', function (): void {
    $source = new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated')]);
    $restriction = new RecordingRestriction(deny: true, exempt: true);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->restrictions([$restriction]));
    $decision = $engine->decide($panel, AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy')));
    expect($decision->reason)->toBe(DecisionReason::Restricted)->and($source->roleReads)->toBe(0)->and($source->grantReads)->toBe(0);
});

it('does not retain admin qualification after a later source error in either source order', function (bool $reverse): void {
    $good = new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated')]);
    $bad = new FailingSource(name: 'broken');
    [$engine, $panel, $request] = AuthorizationWorld::compile($reverse ? $bad : $good, extra: [$reverse ? $good : $bad]);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError)
        ->and($engine->isSuperAdmin($panel, $request->subject(), AccessScope::in(TenantRef::global())))->toBeFalse();
})->with([false, true]);

it('does not request global roles from a required tenant without an allow list', function (): void {
    $global = AccessScope::in(TenantRef::global());
    $source = new ScopeSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), $global, 'generated')]);
    [$engine, $panel, $request] = ScopeWorld::compile($source, configure: fn (PanelBuilder $panel) => $panel->roles([GrantableRootRole::class]));
    $scope = ScopeWorld::scope();
    expect($engine->decide($panel, $request->inScope($scope))->reason)->toBe(DecisionReason::NotGranted)
        ->and($engine->isSuperAdmin($panel, $request->subject(), $scope))->toBeFalse()
        ->and(array_any($source->roleScopes, fn (AccessScope $scope): bool => $scope->tenant->isGlobal()))->toBeFalse();
});

it('an allow listed role never turns ordinary global direct grants into tenant authority', function (): void {
    $source = new ScopeSource(direct: [AuthorizationWorld::grant()]);
    [$engine, $panel, $request] = ScopeWorld::compile($source, configure: fn (PanelBuilder $panel) => $panel->roles([GrantableRootRole::class])->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership)->allowGlobalRoles([GrantableRootRole::class])));
    $scope = ScopeWorld::scope();
    expect($engine->decide($panel, $request->inScope($scope))->reason)->toBe(DecisionReason::NotGranted)
        ->and($engine->isSuperAdmin($panel, $request->subject(), $scope))->toBeFalse();
});

it('ordinary contribution condition failure keeps admin while a condition error fails closed', function (bool $error): void {
    $condition = new class($error) implements GrantCondition
    {
        public function __construct(private readonly bool $error) {}

        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            if ($grant instanceof RoleContribution) {
                return true;
            }

            if ($this->error) {
                throw new RuntimeException('ordinary condition unavailable');
            }

            return false;
        }
    };
    $source = new GeneratedSource(direct: [AuthorizationWorld::grant()], roles: [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated')]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->grantConditions([$condition]));
    expect($engine->decide($panel, $request)->reason)->toBe($error ? DecisionReason::ConditionError : DecisionReason::SuperAdmin)
        ->and($engine->isSuperAdmin($panel, $request->subject(), AccessScope::in(TenantRef::global())))->toBe(! $error);
})->with([false, true]);

it('preserves qualification parity for a generic reference without a backing model', function (): void {
    $source = new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated')]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source);
    $subject = SubjectRef::of($request->subject()->type(), 999);
    expect($engine->isSuperAdmin($panel, $subject, AccessScope::in(TenantRef::global())))->toBeTrue()
        ->and($engine->decide($panel, AccessRequest::for($subject, $request->permission()))->reason)->toBe(DecisionReason::SuperAdmin);
});

it('subject resolution errors refuse superadmin qualification', function (): void {
    $source = new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated')]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source);
    Schema::drop('users');
    expect($engine->isSuperAdmin($panel, $request->subject(), AccessScope::in(TenantRef::global())))->toBeFalse()
        ->and($source->roleReads)->toBe(0);
});

it('structural scope rejection also rejects isSuperAdmin before contribution reads', function (): void {
    $source = new ScopeSource(roles: [RoleContribution::of(RoleKey::of('admin', 'tenant-admin'), ScopeWorld::scope(context: 1), 'generated')]);
    $definition = new StoreScope(fn (): null => null);
    app()->instance(StoreScope::class, $definition);
    [$engine, $panel, $request] = ScopeWorld::compile($source, configure: fn (PanelBuilder $panel) => $panel->roles([TenantAdminRole::class]), definition: $definition);
    expect($engine->isSuperAdmin($panel, $request->subject(), ScopeWorld::scope(context: 1)))->toBeFalse()
        ->and($source->roleReads)->toBe(0);
});
