<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Scopes\WithinContext;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Resolvers\ClientScopeResolver;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;

/*
 * The public wrapper over the scope rules of the engine: V12 (tenant, context and resource), V13 (an explicit
 * context in a panel without contexts), V14 (the previous scope survives an exception), V16 (tenant membership),
 * V86 (one user in A and B with different roles, conflicting panel hints) and V88 (missing tenant or resource scope,
 * owner mismatch, even for a super admin). The wrapper decides exactly as the engine does.
 */

beforeEach(function (): void {
    CrmWorld::seed();
    ChangeWorld::panel();
});
afterEach(fn () => CrmWorld::resetRuntime());

function tenantOf(int $id): Organization
{
    return Organization::query()->findOrFail($id);
}

it('V12 decides through the wrapper as the engine for every user, client and tenant', function (int $user, int $client, int $tenant): void {
    $access = User::query()->findOrFail($user)->guard('crm')->inTenant(tenantOf($tenant));

    foreach ([ClientPermission::View, ClientPermission::Update] as $permission) {
        $engine = CrmWorld::decide($access->panel(), $client, $permission, $user, $tenant);
        $decision = $access->decide($permission, on: Client::query()->findOrFail($client));

        expect([$decision->allowed(), $decision->reason])->toBe([$engine->allowed(), $engine->reason]);
    }
})->with(function (): Generator {
    foreach ([1, 2, 3, 4] as $user) {
        foreach ([1, 2, 3, 4, 5, 6] as $client) {
            foreach ([1, 2] as $tenant) {
                yield "user {$user} client {$client} tenant {$tenant}" => [$user, $client, $tenant];
            }
        }
    }
});

it('V13 refuses an explicit context in a panel without contexts', function (): void {
    ChangeWorld::panel(configure: static fn (PanelBuilder $panel) => $panel->scopes(AssignmentScopePolicy::none()));
    $access = User::query()->findOrFail(1)->guard('crm')->inTenant(tenantOf(1));

    expect($access->decide(ClientPermission::ViewAny, on: AssignmentScopeRef::of('crm.project', 1))->reason)
        ->toBe(DecisionReason::AssignmentScopeNotAccepted)
        ->and($access->hasPermission(ClientPermission::ViewAny, on: AssignmentScopeRef::of('crm.project', 1)))->toBeFalse();
});

it('V14 keeps the previous scope when a call fails inside withinScope', function (): void {
    $access = User::query()->findOrFail(1)->guard('crm');
    $panel = $access->panel();
    $outer = CrmWorld::scope(1);
    $current = app(CurrentContext::class);

    app(WithinContext::class)->run($panel, $outer, static function () use ($access, $panel, $current, $outer): void {
        try {
            app(WithinContext::class)->run($panel, CrmWorld::scope(2), static fn (): bool => $access->hasPermission('backoffice:clients.view'));
        } catch (ConflictingPanelException) {
        }
        ClientScopeResolver::$throws = true;

        expect($access->decide(ClientPermission::View, on: Client::query()->findOrFail(1))->allowed())->toBeFalse()
            ->and($current->get($panel))->toBe($outer);
        ClientScopeResolver::$throws = false;

        expect($access->hasPermission(ClientPermission::View, on: Client::query()->findOrFail(1)))->toBeTrue();
    });

    expect($current->get($panel))->toBeNull();
});

it('V16 requires tenant membership: Anna in A and B, outsider nowhere even with a stored grant', function (): void {
    CrmWorld::assign('seller', 4, 1);
    $outsider = User::query()->findOrFail(4)->guard('crm')->inTenant(tenantOf(1));
    $anna = User::query()->findOrFail(1)->guard('crm');

    expect($outsider->decide(ClientPermission::View, on: Client::query()->findOrFail(1))->reason)->toBe(DecisionReason::Restricted)
        ->and($anna->inTenant(tenantOf(1))->hasPermission(ClientPermission::View, on: Client::query()->findOrFail(1)))->toBeTrue()
        ->and($anna->inTenant(tenantOf(2))->hasPermission(ClientPermission::View, on: Client::query()->findOrFail(5)))->toBeTrue();
});

it('V86 keeps panel, tenant and context apart for one user with different roles', function (): void {
    $anna = User::query()->findOrFail(1);
    $a = $anna->guard('crm')->inTenant(tenantOf(1));
    $b = $anna->guard('crm')->inTenant(tenantOf(2));

    expect($a->hasPermission(ClientPermission::Update, on: Client::query()->findOrFail(1)))->toBeTrue()
        ->and($b->hasPermission(ClientPermission::Update, on: Client::query()->findOrFail(5)))->toBeFalse()
        ->and($a->roleNames(on: AssignmentScopeRef::of('crm.project', 4))->all())->toBe([])
        ->and($b->roleNames(on: AssignmentScopeRef::of('crm.project', 4))->all())->toBe(['analyst'])
        ->and(fn () => $anna->hasPermission('backoffice:clients.view', guard: 'crm'))->toThrow(ConflictingPanelException::class)
        ->and(fn () => $a->hasRole('crm:seller', guard: 'backoffice'))->toThrow(ConflictingPanelException::class);
});

it('V88 denies without a tenant or with a foreign owner, even a super admin', function (): void {
    $daria = User::query()->findOrFail(3)->guard('crm');
    $p1 = AssignmentScopeRef::of('crm.project', 1);

    expect($daria->inTenant(tenantOf(1))->isSuperAdmin(on: $p1))->toBeTrue()
        ->and($daria->decide(ClientPermission::View, on: $p1)->reason)->toBe(DecisionReason::TenantRequired)
        ->and($daria->inTenant(tenantOf(2))->decide(ClientPermission::View, on: Client::query()->findOrFail(1))->reason)->toBe(DecisionReason::TenantMismatch)
        ->and($daria->inTenant(tenantOf(1))->decide(ClientPermission::View, on: Client::query()->findOrFail(5))->reason)->toBe(DecisionReason::TenantMismatch)
        ->and($daria->inTenant(tenantOf(2))->isSuperAdmin(on: $p1))->toBeFalse();
});
