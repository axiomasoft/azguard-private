<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\User;

it('promotes a user to super-admin by id', function () {
    $user = User::create([
        'name' => 'Future Admin',
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $this->artisan('guard:super-admin', ['--user' => (string) $user->getKey()])
        ->assertSuccessful();

    $fresh = $user->fresh();
    expect($fresh->hasRole('super-admin'))->toBeTrue();
    // The '*' role short-circuits every check via Gate::before().
    expect($fresh->hasPermission('test.post.delete', 'test'))->toBeTrue();
});

it('fails for an unknown user id', function () {
    $this->artisan('guard:super-admin', ['--user' => '999999'])
        ->assertFailed();
});

it('refuses to adopt a DB-only super-admin name collision', function () {
    Role::query()->create(['name' => 'super-admin', 'level' => 1]);
    $user = User::create([
        'name' => 'Blocked',
        'email' => 'blocked-admin@example.com',
        'password' => 'password',
    ]);

    $this->artisan('guard:super-admin', ['--user' => (string) $user->getKey()])
        ->expectsOutputToContain('Will not adopt a DB-only or foreign row')
        ->assertFailed();

    expect($user->fresh()->hasRole('super-admin'))->toBeFalse();
});
