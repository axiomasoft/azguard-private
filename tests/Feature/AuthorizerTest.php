<?php

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Panels\Panel;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Gate;

test('user with ManagerRole can access test.post.view via Gate', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $role = createRoleWithClass(['name' => 'manager',
        'level' => 0,
    ], ManagerRole::class);

    $user->roles()->attach($role);
    $user->load('roles');

    $panel = Panel::make()->id('test');
    app(AzGuardManagerInterface::class)->setCurrentPanel($panel);

    $this->actingAs($user);

    expect(Gate::allows('test.post.view'))->toBeTrue();
    expect(Gate::allows('test.other.permission'))->toBeFalse();
});
