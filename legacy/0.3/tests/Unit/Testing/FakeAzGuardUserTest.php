<?php

declare(strict_types=1);

use AzGuard\Permissions\PermissionName;
use AzGuard\Testing\FakeAzGuardUser;
use AzGuard\Tests\Stubs\Permissions\TestPermission;

it('falls back to the enum value when the panel is not registered', function (): void {
    expect(PermissionName::resolve(TestPermission::PostView, 'unregistered-panel'))
        ->toBe('post.view');
});

it('answers authenticatable and permission APIs without a database', function (): void {
    $user = (new FakeAzGuardUser(9))->grant('test', TestPermission::PostView);

    expect($user->getAuthIdentifier())->toBe(9)
        ->and($user->getAuthIdentifierName())->toBe('id')
        ->and($user->getAuthPassword())->toBe('')
        ->and($user->getAuthPasswordName())->toBe('password')
        ->and($user->getRememberToken())->toBe('')
        ->and($user->getRememberTokenName())->toBe('')
        ->and($user->hasPermission(TestPermission::PostView, 'test'))->toBeTrue()
        ->and($user->hasPermissionIn('workspace', 1, TestPermission::PostView, 'test'))->toBeFalse()
        ->and($user->hasContextGuard())->toBeFalse()
        ->and($user->permissions('test')->all())->toContain('test.post.view')
        ->and($user->isSuperAdmin())->toBeFalse()
        ->and($user->checkPermission(TestPermission::PostView, 'test'))->toBeTrue();

    $user->setRememberToken('x');
    $user->flushPermissions();

    $wildcard = (new FakeAzGuardUser)->wildcard();

    expect($wildcard->isSuperAdmin())->toBeTrue()
        ->and($wildcard->checkPermission('not a valid key!!!', 'test'))->toBeFalse();
});
