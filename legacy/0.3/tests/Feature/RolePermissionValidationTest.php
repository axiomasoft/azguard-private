<?php

declare(strict_types=1);

use AzGuard\Contracts\RolePermissionValidator;
use AzGuard\Models\Role;
use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;

// A swappable validator that rejects everything, proving the seam is honoured.
class RejectAllRolePermissionValidator implements RolePermissionValidator
{
    public function validate(string $permissionKey, string $panelId): void
    {
        throw new RuntimeException("rejected {$permissionKey}");
    }
}

// F46: opt-in, swappable role-permission validation. Default lenient.

it('rejects an unknown role permission key when validation is enabled', function () {
    config()->set('az-guard.features.validate_role_permissions', true);
    $role = Role::create(['name' => 'editor', 'panel_id' => 'test']);

    expect(fn () => $role->dbPermissions()->create([
        'permission_key' => 'test.nope.unknown',
        'panel_id' => 'test',
    ]))->toThrow(InvalidPermissionKeyException::class);
});

it('accepts a known role permission key when validation is enabled', function () {
    config()->set('az-guard.features.validate_role_permissions', true);
    $role = Role::create(['name' => 'editor', 'panel_id' => 'test']);

    $permission = $role->dbPermissions()->create([
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
    ]);

    expect($permission->exists)->toBeTrue();
});

it('stays lenient by default, allowing an unlinted * key', function () {
    $role = Role::create(['name' => 'editor', 'panel_id' => 'test']);

    $permission = $role->dbPermissions()->create([
        'permission_key' => '*',
        'panel_id' => 'test',
    ]);

    expect($permission->exists)->toBeTrue();
});

it('uses a config-overridden validator', function () {
    config()->set('az-guard.features.validate_role_permissions', true);
    config()->set('az-guard.role_permission_validator', RejectAllRolePermissionValidator::class);
    app()->forgetInstance(RolePermissionValidator::class);

    $role = Role::create(['name' => 'editor', 'panel_id' => 'test']);

    expect(fn () => $role->dbPermissions()->create([
        'permission_key' => 'test.post.view', // valid key, but the stub rejects all
        'panel_id' => 'test',
    ]))->toThrow(RuntimeException::class);
});
