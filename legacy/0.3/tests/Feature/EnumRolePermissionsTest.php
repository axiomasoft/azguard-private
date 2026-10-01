<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;

/**
 * Enum-first role: declares its permissions as enum cases. The owning panel
 * ('test' registers TestPermission) scopes each case to "test.{value}", so the
 * role never spells out the panel prefix.
 */
class EnumEditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [TestPermission::PostView, TestPermission::PostCreate];
    }
}

function makeEnumRoleUser(): User
{
    $role = createRoleWithClass(['name' => 'EnumEditor',
    ], EnumEditorRole::class);

    $user = User::create([
        'name' => 'Enum User',
        'email' => 'enum@example.com',
        'password' => 'password',
    ]);

    $user->roles()->attach($role);

    return $user;
}

it('grants enum-case role permissions scoped to the owning panel', function () {
    $user = makeEnumRoleUser();

    // Checked with the enum case — scoped to the panel automatically.
    expect($user->hasPermission(TestPermission::PostView, 'test'))->toBeTrue();
    expect($user->hasPermission(TestPermission::PostCreate, 'test'))->toBeTrue();

    // Not granted by the role.
    expect($user->hasPermission(TestPermission::PostDelete, 'test'))->toBeFalse();
});

it('resolves enum-case role permissions to the same full string key', function () {
    $user = makeEnumRoleUser();

    // The enum case TestPermission::PostView resolves to "test.post.view".
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();
    expect($user->hasPermission('test.post.create', 'test'))->toBeTrue();
    expect($user->hasPermission('test.post.delete', 'test'))->toBeFalse();
});

it('still supports roles that declare full string permission keys (back-compat)', function () {
    // ManagerRole returns ['test.post.view'] as a plain string key.
    $role = createRoleWithClass(['name' => 'StringManager',
    ], ManagerRole::class);

    $user = User::create([
        'name' => 'String User',
        'email' => 'string@example.com',
        'password' => 'password',
    ]);
    $user->roles()->attach($role);

    expect($user->hasPermission(TestPermission::PostView, 'test'))->toBeTrue();
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();
    expect($user->hasPermission(TestPermission::PostEdit, 'test'))->toBeFalse();
});
