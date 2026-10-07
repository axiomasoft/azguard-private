<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Scopes\ContextMembership;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\ScopeSource;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(fn () => Relation::morphMap([], false));

it('checks active tenant membership independently of grants for A B outsider and error', function (string $tenant, string $subject, bool $throws, ?DecisionReason $reason, string $authority): void {
    $membership = new Membership(members: ['1'], throws: $throws);
    $scope = ScopeWorld::scope($tenant, $tenant === 'A' ? 1 : 2);
    $source = new ScopeSource(direct: $authority === 'grant' ? [ScopeWorld::grant($scope)] : [], roles: $authority === 'admin' ? [RoleContribution::of(RoleKey::of('admin', 'root'), ScopeWorld::scope($tenant), 'generated')] : []);
    [$engine, $panel, $request] = ScopeWorld::compile($source, configure: fn (PanelBuilder $panel) => $panel->tenants(TenantPolicy::required(Organization::class)->requireMembership($membership))->roles([GrantableRootRole::class]));
    $request = AccessRequest::for(SubjectRef::of('user', $subject), PermissionKey::of('admin', $authority === 'policy' ? 'orders.policy' : 'orders.view'))->inScope($scope);
    $decision = $engine->decide($panel, $request);
    expect($decision->allowed())->toBe($reason === null)->and($membership->checks)->toBe(1);

    if ($reason !== null) {
        expect($decision->reason)->toBe($reason)->and($decision->component)->toBe('membership')->and(RuntimePolicy::$calls)->toBe(0);
    }
})->with([
    ['A', '1', false, null], ['B', '1', false, null],
    ['A', '99', false, DecisionReason::Restricted], ['A', '1', true, DecisionReason::RestrictionError],
])->with(['grant', 'policy', 'admin']);

it('makes context membership optional and enforces it only when requested', function (bool $required, bool $member, bool $throws): void {
    $membership = new Membership(members: $member ? ['1'] : [], throws: $throws);
    [$engine, $panel, $request] = ScopeWorld::compile(new ScopeSource(direct: [ScopeWorld::grant(ScopeWorld::scope())]), configure: function (PanelBuilder $panel) use ($required, $membership): void {
        if ($required) {
            $panel->scopes(AssignmentScopePolicy::inherit(StoreScope::class)->requireMembership(new ContextMembership($membership)));
        }
    });
    $decision = $engine->decide($panel, $request->inScope(ScopeWorld::scope(context: 1)));
    expect($membership->checks)->toBe($required ? 1 : 0);

    if (! $required || (! $throws && $member)) {
        expect($decision->allowed())->toBeTrue();

        return;
    }

    expect($decision->reason)->toBe($throws ? DecisionReason::RestrictionError : DecisionReason::Restricted)->and($decision->component)->toBe('membership');
})->with([false, true])->with([false, true])->with([false, true]);
