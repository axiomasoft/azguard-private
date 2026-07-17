<?php

declare(strict_types=1);

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
