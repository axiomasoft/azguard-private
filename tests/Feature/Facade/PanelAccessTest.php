<?php

declare(strict_types=1);

use AzGuard\Authorization\Visibility;
use AzGuard\Changes\GrantFilter;
use AzGuard\Concerns\ScopedPanelAccess;
use AzGuard\Concerns\SubjectAccess;
use AzGuard\Contracts\Catalog\PermissionCatalog;
use AzGuard\Contracts\Changes\GrantManager;
use AzGuard\Contracts\Changes\PermissionManager;
use AzGuard\Contracts\PanelAccess;
use AzGuard\Contracts\Roles\RoleCatalog;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Schema\PanelSchema;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Scopes\WithinContext;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * 05 §2: `AzGuard::panel($id)` is one panel with a fixed (panel, tenant, origin). Every method works in that partition;
 * a manager of another tenant or origin does not see the records of this one.
 */

beforeEach(function (): void {
    CrmWorld::seed();
    ChangeWorld::panel();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

function facadeCrm(int $tenant = 1): PanelAccess
{
    return AzGuard::panel('crm')->inTenant(Organization::query()->findOrFail($tenant));
}

function facadeRequest(int $user = 1, string $permission = 'clients.update', int $tenant = 1): AccessRequest
{
    return AccessRequest::for(SubjectRef::of('crm.user', $user), PermissionKey::of('crm', $permission))
        ->inTenant(TenantRef::of('crm.organization', $tenant))->on(null, Client::query()->findOrFail(1));
}

it('returns the panel access for a registered id and refuses an unknown one', function (): void {
    $access = AzGuard::panel('crm');

    expect($access)->toBeInstanceOf(PanelAccess::class)->toBeInstanceOf(ScopedPanelAccess::class)
        ->and($access->definition())->toBeInstanceOf(Panel::class)
        ->and($access->definition()->id())->toBe('crm')
        ->and(AzGuard::panel('backoffice')->definition()->id())->toBe('backoffice')
        ->and(fn () => AzGuard::panel('nope'))->toThrow(UnknownPanelException::class);
});

it('returns a new access from inTenant and fromOrigin and leaves the first as it was', function (): void {
    $crm = AzGuard::panel('crm');
    $tenant = $crm->inTenant(Organization::query()->findOrFail(1));
    $external = $tenant->fromOrigin('external');

    expect($tenant)->not->toBe($crm)->and($external)->not->toBe($tenant)
        ->and(fn () => $crm->scope())->toThrow(TenantRequiredException::class)
        ->and($tenant->scope()->tenant->key())->toBe('crm.organization:1')
        ->and($crm->inTenant(TenantRef::of('crm.organization', 2))->scope()->tenant->key())->toBe('crm.organization:2')
        ->and($external->scope()->tenant->key())->toBe('crm.organization:1')
        ->and(fn () => $crm->inTenant(User::query()->findOrFail(1)))->toThrow(TenantMismatchException::class)
        ->and(fn () => $crm->inTenant(TenantRef::of('crm.team', 1)))->toThrow(TenantMismatchException::class);
});

it('reads the current tenant of the panel when none was given, and an own tenant wins over it', function (): void {
    $crm = AzGuard::panel('crm');
    $panel = $crm->definition();

    $inside = app(WithinContext::class)->run($panel, CrmWorld::scope(2), static fn (): array => [
        $crm->scope()->tenant->key(),
        $crm->inTenant(Organization::query()->findOrFail(1))->scope()->tenant->key(),
    ]);

    expect($inside)->toBe(['crm.organization:2', 'crm.organization:1'])
        ->and(app(CurrentContext::class)->get($panel))->toBeNull()
        ->and(fn () => $crm->scope())->toThrow(TenantRequiredException::class);
});

it('hands out a subject wrapper in the panel, tenant and origin of the access', function (): void {
    $anna = User::query()->findOrFail(1);
    $access = facadeCrm()->fromOrigin('external');
    $subject = $access->for($anna);

    expect($subject)->toBeInstanceOf(SubjectAccess::class)
        ->and($subject->panel()->id())->toBe('crm')
        ->and($subject->hasPermission(ClientPermission::Update, on: Client::query()->findOrFail(1)))->toBeTrue()
        ->and($access->for(SubjectRef::of('crm.user', 1))->hasPermission(ClientPermission::Update, on: Client::query()->findOrFail(1)))->toBeTrue()
        ->and($subject->grantRole('caller', on: AssignmentScopeRef::of('crm.project', 5))->applied())->toBeTrue()
        ->and(ChangeWorld::keys())->toContain('crm.organization:1|caller|1|crm.project:5|external')
        ->and(fn () => $access->for(new GenericUser(['id' => 1])))->toThrow(SubjectNotAcceptedException::class)
        ->and(fn () => $access->for(SubjectRef::of('crm.team', 1)))->toThrow(SubjectNotAcceptedException::class);
});

it('keeps the grants of another tenant or origin out of the grant manager and refuses their ids in bulk', function (): void {
    $anna = User::query()->findOrFail(1);
    $p5 = AssignmentScopeRef::of('crm.project', 5);
    $external = facadeCrm()->fromOrigin('external');
    $id = $external->for($anna)->grantRole('caller', on: $p5)->record?->id ?? throw new RuntimeException('No grant was written.');

    $sees = static fn (PanelAccess $access): bool => $access->grants()->find($id) !== null;
    $listed = static fn (PanelAccess $access): int => count($access->grants()->page(new GrantFilter(subject: SubjectRef::of('crm.user', 1), context: $p5))->items);

    expect($external->grants())->toBeInstanceOf(GrantManager::class)
        ->and($sees($external))->toBeTrue()
        ->and($sees(facadeCrm()))->toBeFalse()
        ->and($sees(facadeCrm(2)->fromOrigin('external')))->toBeFalse()
        ->and($sees(AzGuard::panel('backoffice')->inTenant(TenantRef::of('crm.organization', 1))->fromOrigin('external')))->toBeFalse()
        ->and($listed($external))->toBe(1)
        ->and($listed(facadeCrm()))->toBe(0)
        ->and($listed(facadeCrm(2)->fromOrigin('external')))->toBe(0)
        ->and(fn () => facadeCrm()->grants()->revokeMany([$id]))->toThrow(StaleSelectionException::class)
        ->and($sees($external))->toBeTrue()
        ->and($external->grants()->revokeMany([$id])->applied())->toBeTrue()
        ->and($sees($external))->toBeFalse();
});

it('needs a tenant for the managers and the schema of a panel with tenants', function (): void {
    $crm = AzGuard::panel('crm');

    foreach (['grants', 'permissions', 'schema', 'state'] as $method) {
        expect(fn () => $crm->{$method}())->toThrow(TenantRequiredException::class);
    }
    expect($crm->roles())->toBeInstanceOf(RoleCatalog::class)
        ->and($crm->catalog())->toBeInstanceOf(PermissionCatalog::class)
        ->and($crm->visibility())->toBeInstanceOf(Visibility::class);
});

it('exposes the code roles, the permission manager, the catalog and the schema of the panel and tenant', function (): void {
    $access = facadeCrm();
    $schema = $access->schema();

    expect($access->roles()->find('seller'))->not->toBeNull()
        ->and($access->roles()->find('nope'))->toBeNull()
        ->and($access->permissions())->toBeInstanceOf(PermissionManager::class)
        ->and($access->catalog()->panel())->toBe('crm')
        ->and($access->catalog()->has('clients.update'))->toBeTrue()
        ->and($schema)->toBeInstanceOf(PanelSchema::class)
        ->and($schema->panel)->toBe('crm')
        ->and($schema->tenant->key())->toBe('crm.organization:1')
        ->and(facadeCrm(2)->schema()->tenant->key())->toBe('crm.organization:2')
        ->and(fn () => AzGuard::panel('crm')->inTenant(TenantRef::of('crm.team', 1))->schema())->toThrow(TenantMismatchException::class);
});

it('decides, decides many and explains in its panel and refuses a request of another panel or tenant', function (): void {
    $access = facadeCrm();
    $request = facadeRequest();
    $denied = facadeRequest(user: 2);

    expect($access->decide($request)->allowed())->toBeTrue()
        ->and($access->decide($denied)->allowed())->toBeFalse()
        ->and($access->decide($denied)->reason)->toBe(DecisionReason::NotGranted)
        ->and($access->explain($request)->decision()->allowed())->toBeTrue()
        ->and(array_map(static fn ($d): bool => $d->allowed(), iterator_to_array($access->decideMany([$request, $denied]))))->toBe([true, false])
        ->and(array_map(static fn ($d): bool => $d->allowed(), iterator_to_array($access->decideMany((static fn () => yield $request)()))))->toBe([true])
        ->and(fn () => $access->decide(AccessRequest::for(SubjectRef::of('crm.user', 1), PermissionKey::of('backoffice', 'clients.update'))))->toThrow(ConflictingPanelException::class)
        ->and(fn () => $access->decideMany([$request, AccessRequest::for(SubjectRef::of('crm.user', 1), PermissionKey::of('backoffice', 'clients.update'))]))->toThrow(ConflictingPanelException::class)
        ->and(fn () => $access->explain(facadeRequest(tenant: 2)))->toThrow(TenantMismatchException::class)
        ->and(fn () => facadeCrm(2)->decide($request))->toThrow(TenantMismatchException::class);
});

it('fills the tenant of a request that names none from the tenant of the access', function (): void {
    $bare = AccessRequest::for(SubjectRef::of('crm.user', 1), PermissionKey::of('crm', 'clients.update'))->on(null, Client::query()->findOrFail(1));

    expect(facadeCrm()->decide($bare)->scope->tenant->key())->toBe('crm.organization:1')
        ->and(facadeCrm(2)->decide($bare)->scope->tenant->key())->toBe('crm.organization:2');
});

it('reads the state of the stored authority and renews it by hand', function (): void {
    $access = facadeCrm();
    $before = $access->state();
    $touched = $access->touch();

    expect($before->panel)->toBe('crm')
        ->and($touched->version)->toBeGreaterThan($before->version)
        ->and($access->state()->version)->toBe($touched->version)
        ->and($access->state()->incarnation)->toBe($before->incarnation);
});

it('refuses the state of a panel that stores nothing', function (): void {
    ChangeWorld::panel(sources: []);

    expect(fn () => facadeCrm()->state())->toThrow(PanelNotWritableException::class);
});
