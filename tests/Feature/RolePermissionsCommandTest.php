<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Support\Config;
use AzGuard\Tests\Stubs\CustomRole;
use AzGuard\Tests\Stubs\CustomRolePermission;

// F32: guard:role-permissions resolves its models via Config::*Model() (no
// hardcode) and validates keys against the catalog on add/sync.

beforeEach(function (): void {
    CustomRole::$retrieved = false;
    CustomRolePermission::$created = false;

    // Canary: the "test" panel catalog must actually contain these keys,
    // otherwise the shadowed published vendor config is in effect and the
    // validation assertions below would be meaningless.
    $catalog = app(PermissionCatalog::class);
    expect($catalog->has('test', 'test.post.view'))->toBeTrue();
    expect($catalog->has('test', 'test.post.unknown'))->toBeFalse();
});

// ─── add: catalog key validation ────────────────────────────────────────────

it('adds a permission whose key is registered in the catalog', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);

    $this->artisan('guard:role-permissions', [
        'action' => 'add',
        'role' => 'editor',
        'permission_key' => 'test.post.view',
        '--panel' => 'test',
    ])
        ->expectsOutputToContain('Added: [test.post.view]')
        ->assertSuccessful();

    expect($role->dbPermissions()->where('permission_key', 'test.post.view')->exists())->toBeTrue();
});

it('reports an unknown key on add and writes nothing', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);

    $this->artisan('guard:role-permissions', [
        'action' => 'add',
        'role' => 'editor',
        'permission_key' => 'test.post.unknown',
        '--panel' => 'test',
    ])
        ->expectsOutputToContain('is not registered in the catalog')
        ->assertFailed();

    expect($role->dbPermissions()->count())->toBe(0);
});

// ─── sync: catalog key validation ────────────────────────────────────────────

it('syncs keys that are all registered in the catalog', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);

    $this->artisan('guard:role-permissions', [
        'action' => 'sync',
        'role' => 'editor',
        '--panel' => 'test',
        '--keys' => 'test.post.view,test.post.create',
        '--force' => true,
    ])
        ->expectsOutputToContain('Sync complete')
        ->assertSuccessful();

    expect($role->dbPermissions()->pluck('permission_key')->sort()->values()->all())
        ->toBe(['test.post.create', 'test.post.view']);
});

it('reports an unknown key on sync and writes nothing', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);

    $this->artisan('guard:role-permissions', [
        'action' => 'sync',
        'role' => 'editor',
        '--panel' => 'test',
        '--keys' => 'test.post.view,test.post.unknown',
        '--force' => true,
    ])
        ->expectsOutputToContain('Unknown permission key(s) for panel [test]')
        ->assertFailed();

    expect($role->dbPermissions()->count())->toBe(0);
});

// ─── Config::*Model() resolution (no hardcode) ────────────────────────────────

it('resolves the role model through Config::roleModel()', function (): void {
    config(['az-guard.models.role' => CustomRole::class]);
    expect(Config::roleModel())->toBe(CustomRole::class);

    CustomRole::query()->create(['name' => 'editor', 'level' => 10]);
    CustomRole::$retrieved = false;

    $this->artisan('guard:role-permissions', [
        'action' => 'list',
        'role' => 'editor',
        '--panel' => 'test',
    ])->assertSuccessful();

    // The command looked the role up through the custom model, not the
    // hardcoded Role class.
    expect(CustomRole::$retrieved)->toBeTrue();
});

it('resolves the role-permission model through Config::rolePermissionModel() on add', function (): void {
    config(['az-guard.models.role_permission' => CustomRolePermission::class]);
    expect(Config::rolePermissionModel())->toBe(CustomRolePermission::class);

    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);

    $this->artisan('guard:role-permissions', [
        'action' => 'add',
        'role' => 'editor',
        'permission_key' => 'test.post.view',
        '--panel' => 'test',
    ])->assertSuccessful();

    // The row was persisted through the custom model, not the hardcoded one.
    expect(CustomRolePermission::$created)->toBeTrue();
    expect($role->dbPermissions()->where('permission_key', 'test.post.view')->exists())->toBeTrue();
});
