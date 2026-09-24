<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\Roles\SalesAdminRole;
use AzGuard\Tests\Stubs\Roles\SupportAdminRole;
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

it('distinguishes two panel-qualified admin roles by class and exact name', function (): void {
    $sales = createRoleWithClass(['name' => 'sales:admin', 'level' => 7], SalesAdminRole::class);
    $support = createRoleWithClass(['name' => 'support:admin', 'level' => 0], SupportAdminRole::class);
    $dbOnly = Role::query()->create(['name' => 'ops:admin', 'level' => 1]);

    $user = User::create([
        'name' => 'Split',
        'email' => 'split@example.com',
        'password' => 'password',
    ]);
    $user->assignRole($sales, $support, $dbOnly);

    expect($user->hasRole(SalesAdminRole::class))->toBeTrue()
        ->and($user->hasRole(SupportAdminRole::class))->toBeTrue()
        ->and($user->hasRole(new SalesAdminRole))->toBeTrue()
        ->and($user->hasRole('sales:admin'))->toBeTrue()
        ->and($user->hasRole('support:admin'))->toBeTrue()
        ->and($user->hasRole('admin'))->toBeFalse()
        ->and($user->hasRole('ops:admin'))->toBeTrue()
        ->and($user->hasRole(ManagerRole::class))->toBeFalse();

    $other = User::create([
        'name' => 'Other',
        'email' => 'other-role@example.com',
        'password' => 'password',
    ]);
    $other->assignRole('ops:admin');
    expect($other->hasRole(SalesAdminRole::class))->toBeFalse();
});

it('does not resolve a missing class through a same-named DB-only role', function (): void {
    Role::query()->create(['name' => 'manager', 'level' => 1]);
    $user = User::create([
        'name' => 'Plain',
        'email' => 'plain-role@example.com',
        'password' => 'password',
    ]);

    $user->assignRole(ManagerRole::class);

    expect($user->fresh()->roles()->count())->toBe(0)
        ->and($user->fresh()->hasRole(ManagerRole::class))->toBeFalse()
        ->and($user->fresh()->hasRole('manager'))->toBeFalse();
});
