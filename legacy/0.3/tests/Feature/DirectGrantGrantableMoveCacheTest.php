<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Stubs\User;

/**
 * P1.1 / C-09 extension: reassigning a direct grant to another grantable must
 * flush typed identity for BOTH the original and the current subject.
 */
beforeEach(function (): void {
    config()->set('cache.stores.azguard_test', ['driver' => 'array']);
    config()->set('az-guard.cache.store', 'azguard_test');
});

it('invalidates the original grantable cache when grantable_id and type change', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $grant = AzGuard::forUser($userA)->on('test')->grant('test.post.view');

    expect($userA->hasPermission('test.post.view', 'test'))->toBeTrue()
        ->and($userB->hasPermission('test.post.view', 'test'))->toBeFalse();

    app()->forgetScopedInstances();

    $grant->update([
        'grantable_type' => $userB->getMorphClass(),
        'grantable_id' => $userB->getKey(),
    ]);

    app()->forgetScopedInstances();

    expect($userA->hasPermission('test.post.view', 'test'))->toBeFalse()
        ->and($userB->hasPermission('test.post.view', 'test'))->toBeTrue();
});
