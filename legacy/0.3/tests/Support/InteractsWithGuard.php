<?php

declare(strict_types=1);

namespace AzGuard\Tests\Support;

use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

function actingAsGuardRole(string $roleClass): User
{
    $user = User::query()->create([
        'name' => 'Guard Test',
        'email' => 'guard-test@example.com',
        'password' => 'secret',
    ]);

    $role = createRoleWithClass([
        'name' => 'test-'.class_basename($roleClass),
        'level' => 10,
    ], $roleClass);

    $user->roles()->attach($role);
    test()->actingAs(user: $user);

    return $user;
}

function assertGateAllows(string $panel, UnitEnum $permission, mixed ...$arguments): void
{
    $ability = AzGuard::permission(panelId: $panel, permission: $permission);

    expect(Gate::allows(ability: $ability, arguments: $arguments))->toBeTrue();
}
