<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;

function makeRoleResolutionUser(): User
{
    // A DB role backed by a PHP role class. ManagerRole::getName() === 'manager'.
    createRoleWithClass(['name' => 'manager',
    ], ManagerRole::class);

    return User::create([
        'name' => 'Role User',
        'email' => 'role@example.com',
        'password' => 'password',
    ]);
}

it('assigns a role by its class-string (resolved via class_name)', function () {
    $user = makeRoleResolutionUser();

    $user->assignRole(ManagerRole::class);

    expect($user->roles()->where('name', 'manager')->exists())->toBeTrue();
});

it('checks a role by its class-string', function () {
    $user = makeRoleResolutionUser();
    $user->assignRole(ManagerRole::class);

    expect($user->hasRole(ManagerRole::class))->toBeTrue();
    // Back-compat: checking by plain name still works.
    expect($user->hasRole('manager'))->toBeTrue();
    expect($user->hasRole('nonexistent'))->toBeFalse();
});

it('removes a role by its class-string', function () {
    $user = makeRoleResolutionUser();
    $user->assignRole(ManagerRole::class);
    expect($user->hasRole(ManagerRole::class))->toBeTrue();

    $user->removeRole(ManagerRole::class);

    expect($user->fresh()->hasRole(ManagerRole::class))->toBeFalse();
});
