<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Facades\AzGuard;
use AzGuard\Models\DirectGrant;
use AzGuard\Models\Role;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use AzGuard\Roles\RolePermissionSelection;
use AzGuard\Roles\RolePermissionSynchronizer;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Illuminate\Support\Facades\DB;

function permissionRevision(): int
{
    return app(PermissionStateRevision::class)->current();
}

function freezePermissionRevision(int $revision): void
{
    DB::table(Config::permissionStateTable())
        ->where('id', PermissionStateRevision::SINGLETON_ID)
        ->update(['revision' => $revision]);
}

beforeEach(function (): void {
    config()->set('cache.stores.azguard_test', ['driver' => 'array']);
    config()->set('az-guard.cache.store', 'azguard_test');
});

it('keeps a refill started at N on N and serves N+1 only after commit', function (): void {
    $user = User::factory()->create();
    $before = permissionRevision();

    AzGuard::forUser($user)->on('test')->grant('test.post.view');

    expect(permissionRevision())->toBe($before + 1)
        ->and($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    app()->forgetScopedInstances();

    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();
});

it('bypasses reusable caches for uncommitted 8 and cannot reuse a rolled-back allow under a later 8', function (): void {
    $user = User::factory()->create();
    AzGuard::forUser($user)->on('test')->grant('test.post.view');
    freezePermissionRevision(7);
    app()->forgetScopedInstances();

    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue()
        ->and(permissionRevision())->toBe(7);

    $connection = app(PermissionStateRevision::class)->connection();
    $connection->beginTransaction();
    AzGuard::forUser($user)->on('test')->grant('test.post.create');
    $connection->beginTransaction();
    $connection->commit();

    expect(permissionRevision())->toBe(8)
        ->and($user->hasPermission('test.post.create', 'test'))->toBeTrue()
        ->and($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    $connection->rollBack();
    app()->forgetScopedInstances();

    expect(permissionRevision())->toBe(7)
        ->and($user->hasPermission('test.post.create', 'test'))->toBeFalse()
        ->and($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    AzGuard::forUser($user)->on('test')->grant('test.post.edit');
    app()->forgetScopedInstances();

    expect(permissionRevision())->toBe(8)
        ->and($user->hasPermission('test.post.create', 'test'))->toBeFalse()
        ->and($user->hasPermission('test.post.edit', 'test'))->toBeTrue()
        ->and($user->hasPermission('test.post.view', 'test'))->toBeTrue();
});

it('rolls grant, revoke and role-permission sync back when revision write fails', function (): void {
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);

    DB::table(Config::permissionStateTable())->delete();

    expect(fn () => AzGuard::forUser($user)->on('test')->grant('test.post.view'))
        ->toThrow(RuntimeException::class, 'AzGuard permission-state row is missing.');
    expect(DirectGrant::query()->count())->toBe(0);

    expect(fn () => app(RolePermissionSynchronizer::class)->sync(
        role: $role,
        selection: RolePermissionSelection::panelReplacement(panel: 'test', keys: ['test.post.view']),
    ))->toThrow(RuntimeException::class, 'AzGuard permission-state row is missing.');
    expect($role->dbPermissions()->count())->toBe(0);

    DB::table(Config::permissionStateTable())->insert([
        'id' => PermissionStateRevision::SINGLETON_ID,
        'revision' => 1,
    ]);
    AzGuard::forUser($user)->on('test')->grant('test.post.view');
    expect(DirectGrant::query()->count())->toBe(1);

    DB::table(Config::permissionStateTable())->delete();
    expect(fn () => AzGuard::forUser($user)->on('test')->revoke('test.post.view'))
        ->toThrow(RuntimeException::class, 'AzGuard permission-state row is missing.');
    expect(DirectGrant::query()->count())->toBe(1);
});

it('does not bump revision on no-op grant, attach or sync retry', function (): void {
    $user = User::factory()->create();
    $role = createRoleWithClass(['name' => 'manager', 'level' => 10], ManagerRole::class);
    $synchronizer = app(RolePermissionSynchronizer::class);
    $selection = RolePermissionSelection::panelReplacement(panel: 'test', keys: ['test.post.view']);

    AzGuard::forUser($user)->on('test')->grant('test.post.view');
    $afterGrant = permissionRevision();
    AzGuard::forUser($user)->on('test')->grant('test.post.view');
    expect(permissionRevision())->toBe($afterGrant);

    $user->assignRole($role);
    $afterAssign = permissionRevision();
    $user->assignRole($role);
    expect(permissionRevision())->toBe($afterAssign);

    $synchronizer->sync(role: $role, selection: $selection);
    $afterSync = permissionRevision();
    $synchronizer->sync(role: $role, selection: $selection);
    expect(permissionRevision())->toBe($afterSync);
});

it('propagates a role-permission change to two morph types without Role::users()', function (): void {
    $role = Role::query()->create(['name' => 'db-editor', 'level' => 4]);
    $synchronizer = app(RolePermissionSynchronizer::class);
    $synchronizer->sync(
        role: $role,
        selection: RolePermissionSelection::panelReplacement(panel: 'test', keys: ['test.post.view']),
    );

    $user = User::factory()->create();
    $other = UserWithDirectGrants::factory()->create();
    $user->assignRole($role);
    $other->assignRole($role);

    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue()
        ->and($other->hasPermission('test.post.view', 'test'))->toBeTrue();

    $synchronizer->sync(
        role: $role,
        selection: RolePermissionSelection::panelReplacement(panel: 'test', keys: []),
    );
    app()->forgetScopedInstances();

    expect($user->hasPermission('test.post.view', 'test'))->toBeFalse()
        ->and($other->hasPermission('test.post.view', 'test'))->toBeFalse();
});

it('does not restore revoked rights from a loaded roles relation', function (): void {
    $user = User::factory()->create();
    $role = createRoleWithClass(['name' => 'manager', 'level' => 10], ManagerRole::class);
    $user->assignRole($role);
    $stale = $user->roles()->get();
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    $user->removeRole($role);
    $user->setRelation('roles', $stale);
    app()->forgetScopedInstances();

    expect($user->relationLoaded('roles'))->toBeTrue()
        ->and($user->hasPermission('test.post.view', 'test'))->toBeFalse();
});

it('fails before write when the mutation connection does not match permission-state', function (): void {
    $user = new class extends User
    {
        protected $connection = 'azguard_other';
    };
    $user->forceFill(['name' => 'split', 'email' => 'split@example.test', 'password' => 'password']);

    expect(fn () => $user->assignRole('manager'))
        ->toThrow(RuntimeException::class);
});
