<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Roles\RolePermissionSelection;
use AzGuard\Roles\RolePermissionSynchronizer;

it('filament managed universe removes only the unchecked rendered key', function (): void {
    $role = Role::query()->create(['name' => 'editor', 'level' => 10]);
    $role->dbPermissions()->create(['panel_id' => 'test', 'permission_key' => 'test.post.view']);
    $role->dbPermissions()->create(['panel_id' => 'test', 'permission_key' => 'dynamic.concrete']);
    $synchronizer = app(RolePermissionSynchronizer::class);
    $managed = [['test', 'test.post.view'], ['test', 'test.post.create']];

    $synchronizer->sync(
        role: $role,
        selection: RolePermissionSelection::managedSubset(
            managed: $managed,
            desired: [['test', 'test.post.create']],
            expectedFingerprint: $synchronizer->fingerprint(role: $role, managed: $managed),
        ),
    );

    expect($role->dbPermissions()->orderBy('permission_key')->pluck('permission_key')->all())
        ->toBe(['dynamic.concrete', 'test.post.create']);
});
