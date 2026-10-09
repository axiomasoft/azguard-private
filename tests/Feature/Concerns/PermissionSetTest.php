<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Concerns\EveryoneMember;
use AzGuard\Tests\Fixtures\Concerns\LockedRestriction;
use AzGuard\Tests\Fixtures\Concerns\Member;
use AzGuard\Tests\Fixtures\Concerns\SubjectWorld;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/*
 * permissionSet()/permissionNames()/roleNames(): the qualified contributions of the scope, roles expanded by their code
 * definitions, a super-admin role covering every Grants permission, PolicyOnly never included, the earliest expiry as
 * validUntil. Not a decision: decide() stays the answer to a check (16 §8).
 */

beforeEach(fn () => SubjectWorld::seed());

/** @return list<string> */
function setLocals(Member $member, ?string $guard = null): array
{
    return array_map(static fn (PermissionPattern $pattern): string => $pattern->local(), $member->permissionSet(guard: $guard)->patterns());
}

it('expands roles and patterns to the Grants permissions of the catalog and leaves PolicyOnly out', function (): void {
    SubjectWorld::compile();
    $member = SubjectWorld::member();
    $member->grantRole('support');
    $member->grantPermission('users.delete');

    expect(setLocals($member))->toBe(['orders.refund', 'orders.update', 'orders.view', 'users.delete'])
        ->and($member->hasPermission('profile.view'))->toBeTrue()
        ->and(setLocals($member))->not->toContain('profile.view')
        ->and($member->permissionSet()->validUntil())->toBeNull()
        ->and($member->permissionSet()->covers(PermissionKey::of('admin', 'orders.view')))->toBeTrue()
        ->and($member->permissionSet()->covers(PermissionKey::of('admin', 'reports.export')))->toBeFalse();
});

it('gives a super-admin role every Grants permission of the catalog and nothing of PolicyOnly', function (): void {
    SubjectWorld::compile();
    $member = SubjectWorld::member();
    $member->grantRole('root');

    expect(setLocals($member))->toEqualCanonicalizing(['orders.view', 'orders.update', 'orders.refund', 'users.delete', 'reports.export'])
        ->and($member->isSuperAdmin())->toBeTrue()
        ->and($member->roleNames()->all())->toBe(['root']);
});

it('is valid until the earliest expiry of a contribution that adds to it', function (): void {
    SubjectWorld::compile();
    $member = SubjectWorld::member();
    $member->grantRole('manager', until: Carbon::parse('2026-12-01T00:00:00Z'));
    $member->grantPermission('orders.refund', until: Carbon::parse('2026-11-01T00:00:00Z'));
    $member->grantPermission('users.delete');

    expect($member->permissionSet()->validUntil()?->format(DATE_ATOM))->toBe('2026-11-01T00:00:00+00:00');

    Carbon::setTestNow('2026-11-15T00:00:00Z');

    expect(setLocals($member))->toBe(['orders.update', 'orders.view', 'users.delete'])
        ->and($member->permissionSet()->validUntil()?->format(DATE_ATOM))->toBe('2026-12-01T00:00:00+00:00');
});

it('merges exact and wildcard patterns once and bounds the set by a wildcard grant that only repeats them', function (): void {
    SubjectWorld::compile();
    $member = SubjectWorld::member();
    $member->grantRole('manager');
    $member->grantPermission('orders.**', until: Carbon::parse('2026-11-01T00:00:00Z'));

    expect(setLocals($member))->toBe(['orders.refund', 'orders.update', 'orders.view'])
        ->and($member->permissionSet()->validUntil()?->format(DATE_ATOM))->toBe('2026-11-01T00:00:00+00:00');
});

it('is not a decision: a restriction denies a permission the set still lists', function (): void {
    SubjectWorld::compile(admin: static fn (PanelBuilder $panel) => $panel->restrictions([new LockedRestriction]));
    $member = SubjectWorld::member();
    $member->grantRole('manager');

    expect($member->hasPermission('orders.view'))->toBeFalse()
        ->and(setLocals($member))->toBe(['orders.update', 'orders.view']);
});

it('returns permission sets of the panels without tenants only and asks for a tenant for roles', function (): void {
    SubjectWorld::compile();
    $member = SubjectWorld::member();
    $member->grantRole('manager');
    $member->guard('cabinet')->grantRole('editor');

    expect(array_keys($member->azguard()->permissions()))->toBe(['admin', 'cabinet'])
        ->and(array_map(static fn ($set): int => count($set->patterns()), $member->azguard()->permissions()))->toBe(['admin' => 2, 'cabinet' => 1])
        ->and($member->azguard()->roles())->toBe(['admin' => ['manager'], 'cabinet' => ['editor']])
        ->and($member->azguard()->panels())->toBe(['admin', 'cabinet'])
        ->and($member->azguard()->default())->toBe('admin');

    Member::$default = 'cabinet';
    expect($member->azguard()->default())->toBe('cabinet');
});

it('never aggregates tenants: a tenant panel without a tenant is TenantRequiredException for lists, roles and changes', function (): void {
    Schema::create('organizations', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Relation::morphMap(['crm.organization' => Organization::class], false);
    Organization::query()->insert([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]);
    SubjectWorld::compile(cabinet: static fn (PanelBuilder $panel) => $panel->tenants(TenantPolicy::required(Organization::class)->requireMembership(new EveryoneMember)));
    $member = SubjectWorld::member();
    $a = Organization::query()->findOrFail(1);

    expect(fn () => $member->guard('cabinet')->roleNames())->toThrow(TenantRequiredException::class)
        ->and(fn () => $member->guard('cabinet')->permissionSet())->toThrow(TenantRequiredException::class)
        ->and(fn () => $member->guard('cabinet')->isSuperAdmin())->toThrow(TenantRequiredException::class)
        ->and(fn () => $member->guard('cabinet')->grantRole('editor'))->toThrow(TenantRequiredException::class)
        ->and(fn () => $member->azguard()->roles())->toThrow(TenantRequiredException::class)
        ->and(array_keys($member->azguard()->permissions()))->toBe(['admin'])
        ->and($member->guard('cabinet')->inTenant($a)->grantRole('editor')->applied())->toBeTrue()
        ->and($member->guard('cabinet')->inTenant($a)->roleNames()->all())->toBe(['editor'])
        ->and($member->guard('cabinet')->inTenant(Organization::query()->findOrFail(2))->roleNames()->all())->toBe([])
        ->and(SubjectWorld::roleRows())->toBe(['cabinet|editor|1|global|manual']);
});

it('answers roleNames and permissionSet through the engine path of isSuperAdmin', function (): void {
    SubjectWorld::compile();
    $member = SubjectWorld::member();
    $member->grantRole('manager');
    $panel = app(PanelRegistry::class)->get('admin');
    $engine = app(Authorizer::class);
    $scope = AccessScope::in(TenantRef::global());

    expect(array_map(static fn ($role): string => $role->full(), $engine->roles($panel, $member->azguardRef(), $scope)))->toBe(['admin:manager'])
        ->and(array_map(static fn ($p): string => $p->full(), $engine->permissionSet($panel, $member->azguardRef(), $scope)->patterns()))
        ->toBe(['admin:orders.update', 'admin:orders.view']);

    // A stored role that is no longer a code role of the panel contributes nothing.
    app(StorageRegistry::class)->get('default')->mutate('admin', static function (StorageMutation $mutation): void {
        $mutation->table('role_grants')->update(['role' => 'removed']);
        $mutation->touch('admin');
    });

    expect($member->roleNames()->all())->toBe([])
        ->and($member->permissionSet()->patterns())->toBe([]);
});
