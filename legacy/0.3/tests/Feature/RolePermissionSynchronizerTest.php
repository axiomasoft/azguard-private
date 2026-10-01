<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;
use AzGuard\Roles\RolePermissionConnectionException;
use AzGuard\Roles\RolePermissionSelection;
use AzGuard\Roles\RolePermissionSyncConflictException;
use AzGuard\Roles\RolePermissionSynchronizer;

it('rejects an unknown key before writing', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);
    $role->dbPermissions()->create([
        'panel_id' => 'test',
        'permission_key' => 'test.post.view',
    ]);

    expect(fn () => app(RolePermissionSynchronizer::class)->sync(
        role: $role,
        selection: RolePermissionSelection::panelReplacement(panel: 'test', keys: ['test.post.unknown']),
    ))->toThrow(InvalidPermissionKeyException::class);

    expect($role->dbPermissions()->pluck('permission_key')->all())->toBe(['test.post.view']);
});

it('rolls back a delete when a later insert fails', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);
    $role->dbPermissions()->create([
        'panel_id' => 'test',
        'permission_key' => 'test.post.view',
    ]);
    config(['az-guard.models.role_permission' => ExplodingRolePermission::class]);
    ExplodingRolePermission::$fail = true;

    expect(fn () => app(RolePermissionSynchronizer::class)->sync(
        role: $role,
        selection: RolePermissionSelection::panelReplacement(panel: 'test', keys: ['test.post.create']),
    ))->toThrow(RuntimeException::class);

    config(['az-guard.models.role_permission' => RolePermission::class]);

    expect($role->dbPermissions()->pluck('permission_key')->all())->toBe(['test.post.view']);
});

it('collapses duplicate keys and treats an exact retry as a no-op', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);
    $synchronizer = app(RolePermissionSynchronizer::class);
    $selection = RolePermissionSelection::panelReplacement(
        panel: 'test',
        keys: ['test.post.view', 'test.post.view', 'test.post.create'],
    );

    $first = $synchronizer->sync(role: $role, selection: $selection);
    $second = $synchronizer->sync(role: $role, selection: $selection);

    expect($first->changed())->toBeTrue()
        ->and($second->changed())->toBeFalse()
        ->and($role->dbPermissions()->pluck('permission_key')->sort()->values()->all())
        ->toBe(['test.post.create', 'test.post.view']);
});

it('gives the second editor from one snapshot a conflict and leaves rows unchanged', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);
    $role->dbPermissions()->create([
        'panel_id' => 'test',
        'permission_key' => 'test.post.view',
    ]);
    $synchronizer = app(RolePermissionSynchronizer::class);
    $managed = [['test', 'test.post.view'], ['test', 'test.post.create']];
    $fingerprint = $synchronizer->fingerprint(role: $role, managed: $managed);

    $synchronizer->sync(
        role: $role,
        selection: RolePermissionSelection::managedSubset(
            managed: $managed,
            desired: [['test', 'test.post.create']],
            expectedFingerprint: $fingerprint,
        ),
    );

    expect(fn () => $synchronizer->sync(
        role: $role,
        selection: RolePermissionSelection::managedSubset(
            managed: $managed,
            desired: [['test', 'test.post.view']],
            expectedFingerprint: $fingerprint,
        ),
    ))->toThrow(RolePermissionSyncConflictException::class);

    expect($role->dbPermissions()->pluck('permission_key')->all())->toBe(['test.post.create']);
});

it('keeps wildcard, unknown-panel and non-rendered rows when a visible key is unchecked', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);
    $role->dbPermissions()->create(['panel_id' => 'test', 'permission_key' => 'test.*']);
    $role->dbPermissions()->create(['panel_id' => 'test', 'permission_key' => 'test.post.view']);
    $role->dbPermissions()->create(['panel_id' => 'other', 'permission_key' => 'other.secret']);
    $synchronizer = app(RolePermissionSynchronizer::class);
    $managed = [['test', 'test.post.view'], ['test', 'test.post.create']];

    $synchronizer->sync(
        role: $role,
        selection: RolePermissionSelection::managedSubset(
            managed: $managed,
            desired: [],
            expectedFingerprint: $synchronizer->fingerprint(role: $role, managed: $managed),
        ),
    );

    expect($role->dbPermissions()->orderBy('permission_key')->pluck('permission_key')->all())
        ->toBe(['other.secret', 'test.*']);
});

it('rejects role and role-permission models on different connections before writing', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);
    $role->dbPermissions()->create([
        'panel_id' => 'test',
        'permission_key' => 'test.post.view',
    ]);
    config(['az-guard.models.role_permission' => OtherConnectionRolePermission::class]);

    expect(fn () => app(RolePermissionSynchronizer::class)->sync(
        role: $role,
        selection: RolePermissionSelection::singleKey(panel: 'test', key: 'test.post.create', present: true),
    ))->toThrow(RolePermissionConnectionException::class);

    config(['az-guard.models.role_permission' => RolePermission::class]);

    expect(Config::rolePermissionModel())->toBe(RolePermission::class)
        ->and($role->dbPermissions()->pluck('permission_key')->all())->toBe(['test.post.view']);
});

class ExplodingRolePermission extends RolePermission
{
    public static bool $fail = false;

    protected static function booted(): void
    {
        parent::booted();

        static::creating(static function (): void {
            if (self::$fail) {
                throw new RuntimeException('injected insert failure');
            }
        });
    }
}

class OtherConnectionRolePermission extends RolePermission
{
    protected $connection = 'azguard_other';
}
