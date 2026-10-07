<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmRoleGrant;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;

/*
 * on: is a context or a resource. A model of an assignment scope type is that context; any other model is a resource.
 * Checks hand resources to the engine as GateBridge does; role lists take the scope the panel resolves; changes refuse
 * a resource in place of a context. AnyAssignmentScope is for revocations and stored-grant lists only.
 */

beforeEach(function (): void {
    CrmWorld::seed();
    ChangeWorld::panel();
});
afterEach(fn () => CrmWorld::resetRuntime());

function crmA(): Organization
{
    return Organization::query()->findOrFail(1);
}

it('maps a context model, a reference and a resource of a check exactly as the engine request', function (): void {
    $anna = User::query()->findOrFail(1);
    $access = $anna->guard('crm')->inTenant(crmA());
    $engine = app(Authorizer::class);
    $panel = $access->panel();
    $request = static fn (ClientPermission $permission): AccessRequest => AccessRequest::for($anna->azguardRef(), PermissionKey::of('crm', $permission->value))
        ->inTenant(CrmWorld::scope()->tenant);

    foreach ([1, 2, 3, 4] as $client) {
        $resource = Client::query()->findOrFail($client);
        expect($access->decide(ClientPermission::View, on: $resource)->reason)
            ->toBe($engine->decide($panel, $request(ClientPermission::View)->on(null, $resource))->reason);
    }

    foreach ([1, 2, 3] as $project) {
        $model = Project::query()->findOrFail($project);
        $ref = AssignmentScopeRef::of('crm.project', $project);
        $expected = $engine->decide($panel, $request(ClientPermission::ViewAny)->on($ref))->reason;

        expect($access->decide(ClientPermission::ViewAny, on: $model)->reason)->toBe($expected)
            ->and($access->decide(ClientPermission::ViewAny, on: $ref)->reason)->toBe($expected);
    }

    expect($access->hasPermission(ClientPermission::ViewAny, on: Project::query()->findOrFail(1)))->toBeTrue()
        ->and($access->decide(ClientPermission::ViewAny)->reason)->toBe(DecisionReason::NotGranted)
        ->and($access->decide(ClientPermission::View, on: Client::query()->findOrFail(5))->reason)->toBe(DecisionReason::TenantMismatch);
});

it('takes the scope of a resource for role lists and the context of a context model', function (): void {
    $anna = User::query()->findOrFail(1);
    $access = $anna->guard('crm')->inTenant(crmA());

    expect($access->roleNames(on: Project::query()->findOrFail(1))->all())->toBe(['seller'])
        ->and($access->roleNames(on: Client::query()->findOrFail(1))->all())->toBe(['seller'])
        ->and($access->roleNames(on: Client::query()->findOrFail(3))->all())->toBe(['analyst'])
        ->and($access->roleNames()->all())->toBe([])
        ->and($access->hasRole('analyst', on: AssignmentScopeRef::of('crm.project', 2)))->toBeTrue()
        ->and(fn () => $access->roleNames(on: Client::query()->findOrFail(5)))->toThrow(TenantMismatchException::class)
        ->and(fn () => $access->roleNames(on: Organization::query()->findOrFail(1)))->toThrow(AssignmentScopeNotAcceptedException::class);
});

it('writes into a context and refuses a resource in place of a context', function (): void {
    $boris = User::query()->findOrFail(2);
    $access = $boris->guard('crm')->inTenant(crmA());
    $before = ChangeWorld::keys();

    expect(fn () => $access->grantRole('analyst', on: Client::query()->findOrFail(1)))->toThrow(AssignmentScopeNotAcceptedException::class)
        ->and(fn () => $access->revokeRole('seller', on: Client::query()->findOrFail(3)))->toThrow(AssignmentScopeNotAcceptedException::class)
        ->and(ChangeWorld::keys())->toBe($before)
        ->and($access->grantRole('analyst', on: Project::query()->findOrFail(1))->applied())->toBeTrue()
        ->and($access->grantRole('analyst', on: AssignmentScopeRef::of('crm.project', 5))->applied())->toBeTrue()
        ->and(array_values(array_diff(ChangeWorld::keys(), $before)))->toBe([
            'crm.organization:1|analyst|2|crm.project:1|manual',
            'crm.organization:1|analyst|2|crm.project:5|manual',
        ]);
});

it('revokes and lists in every context of the tenant only with AnyAssignmentScope', function (): void {
    $anna = User::query()->findOrFail(1);
    $access = $anna->guard('crm')->inTenant(crmA());
    $access->grantRole('analyst', on: AssignmentScopeRef::of('crm.project', 5));

    expect($access->roleGrants()->all())->toBe([])
        ->and($access->roleGrants(on: AnyAssignmentScope::all())->map(static fn ($grant): string => $grant->roleKey()->key().'@'.$grant->assignmentScopeRef()->key())->all())
        ->toBe(['seller@crm.project:1', 'analyst@crm.project:2', 'analyst@crm.project:5'])
        ->and($access->roleGrants(on: Project::query()->findOrFail(2))->count())->toBe(1)
        ->and($access->revokeRole('analyst', on: AnyAssignmentScope::all())->records)->toHaveCount(2)
        ->and($access->roleGrants(on: AnyAssignmentScope::all())->map(static fn ($grant): string => $grant->roleKey()->key())->all())->toBe(['seller'])
        ->and($anna->guard('crm')->inTenant(Organization::query()->findOrFail(2))->roleGrants(on: AnyAssignmentScope::all())->count())->toBe(1);
});

it('returns write-guarded models of the panel with their own fields', function (): void {
    $anna = User::query()->findOrFail(1);
    $grant = $anna->guard('crm')->inTenant(crmA())->roleGrants(on: Project::query()->findOrFail(1))->first();

    expect($grant)->toBeInstanceOf(CrmRoleGrant::class)
        ->and($grant->subjectRef()->key())->toBe($anna->azguardRef()->key())
        ->and(fn () => $grant->delete())->toThrow(UnsupportedDirectWriteException::class);
});
