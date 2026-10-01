<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Stubs\User;

/**
 * C-09: DirectGrant::booted() only flushed the cache for the grant's CURRENT
 * (grantable, panel_id) on update — moving a grant from panel A to panel B left
 * panel A's cached permission set stale until TTL. Uses a persistent cache
 * store (like CrossRequestCacheInvalidationTest) so a real cross-request read
 * is exercised, not just the per-request scoped cache.
 */
beforeEach(function () {
    config()->set('cache.stores.azguard_test', ['driver' => 'array']);
    config()->set('az-guard.cache.store', 'azguard_test');
});

it('invalidates the OLD panel cache when a grant moves to a different panel', function () {
    $user = User::factory()->create();

    $grant = AzGuard::forUser($user)->on('test')->grant('test.post.view');

    // Request 1: resolve and persist to the cross-request store, for both panels.
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue()
        ->and($user->hasPermission('test.post.view', 'other'))->toBeFalse();

    app()->forgetScopedInstances();

    $grant->update(['panel_id' => 'other']);

    app()->forgetScopedInstances();

    expect($user->hasPermission('test.post.view', 'test'))->toBeFalse()
        ->and($user->hasPermission('test.post.view', 'other'))->toBeTrue();
});
