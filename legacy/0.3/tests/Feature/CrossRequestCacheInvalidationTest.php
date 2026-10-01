<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Stubs\User;

/**
 * P2: with a persistent cache store, a revoke must invalidate the cross-request
 * cache — otherwise a revoked grant would stay live until TTL. The GrantRevoked
 * listener and the DirectGrant model observer handle this.
 */
beforeEach(function () {
    // A named array-backed store persists across forgetScopedInstances() (which
    // only resets the scoped per-request PermissionCache), letting us simulate
    // separate Octane requests against a real cross-request store.
    config()->set('cache.stores.azguard_test', ['driver' => 'array']);
    config()->set('az-guard.cache.store', 'azguard_test');
});

it('invalidates the cross-request cache when a grant is revoked', function () {
    $user = User::factory()->create();

    AzGuard::forUser($user)->on('test')->grant('test.post.view');

    // Request 1: resolve and persist to the cross-request store.
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    // Fresh request — scoped caches reset, the store persists; still served.
    app()->forgetScopedInstances();
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    // Revoke fires GrantRevoked → the cache listener flushes the store key.
    AzGuard::forUser($user)->on('test')->revoke('test.post.view');

    app()->forgetScopedInstances();
    expect($user->hasPermission('test.post.view', 'test'))->toBeFalse();
});

it('reflects a newly granted permission across requests', function () {
    $user = User::factory()->create();

    // Cache an empty result first.
    expect($user->hasPermission('test.post.view', 'test'))->toBeFalse();

    app()->forgetScopedInstances();

    // Grant fires GrantGiven + a model event → store key flushed.
    AzGuard::forUser($user)->on('test')->grant('test.post.view');

    app()->forgetScopedInstances();
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();
});
