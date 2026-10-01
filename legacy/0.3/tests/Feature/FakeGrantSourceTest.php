<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Testing\FakeGrantSource;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\User;

function registerFakeGrantSource(FakeGrantSource $fake): void
{
    app()->instance(FakeGrantSource::class, $fake);
    AzGuard::registerGrantSource(FakeGrantSource::class);
}

function makeFakeSourceUser(): User
{
    return User::create([
        'name' => 'Fake Source User',
        'email' => 'fake@example.com',
        'password' => 'password',
    ]);
}

it('grants faked permissions to any user without DB setup', function () {
    registerFakeGrantSource((new FakeGrantSource)->grant('test', TestPermission::PostView));

    $user = makeFakeSourceUser();

    expect($user->hasPermission(TestPermission::PostView, 'test'))->toBeTrue();
    expect($user->hasPermission(TestPermission::PostDelete, 'test'))->toBeFalse();
});

it('grants everything with wildcard', function () {
    registerFakeGrantSource((new FakeGrantSource)->wildcard());

    $user = makeFakeSourceUser();

    expect($user->hasPermission(TestPermission::PostDelete, 'test'))->toBeTrue();
    expect($user->hasPermission('test.anything.at.all', 'test'))->toBeTrue();
});
