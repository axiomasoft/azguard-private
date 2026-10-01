<?php

declare(strict_types=1);

use AzGuard\Context\ContextGrantBuilder;
use AzGuard\Contracts\ContextGuard as ContextGuardContract;
use AzGuard\Tests\Stubs\User;

/**
 * H1 (PR #91): revoke()/revokeAll() delete context grants with a mass-delete,
 * which fires NO Eloquent model event — so ContextRole::booted()'s deleted()
 * hook never runs. Without the provider-level ContextGrantRevoked listener the
 * durable per-user epoch is never bumped, and on a persistent cache store
 * (redis/file — the context package's target) a revoked grant stays live until
 * TTL. This is the context-package counterpart of the core
 * CrossRequestCacheInvalidationTest.
 */
beforeEach(function (): void {
    // A named array-backed store persists across forgetScopedInstances() (which
    // only resets the scoped per-request caches), standing in for a real
    // cross-request store and letting us simulate separate Octane requests.
    config()->set('cache.stores.azguard_test', ['driver' => 'array']);
    config()->set('az-guard.cache.store', 'azguard_test');
});

it('invalidates the cross-request cache when a context grant is revoked via the CLI', function (): void {
    $user = User::factory()->create();
    $guard = app(ContextGuardContract::class);

    (new ContextGrantBuilder($user))->on('app')->inContext('workspace', 42)->grant('app.posts.edit');

    // Request 1: resolve within the context and persist to the cross-request store.
    expect($guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app'))->toBeTrue();

    // Fresh request — scoped caches reset, the persistent store survives; still served.
    app()->forgetScopedInstances();
    expect($guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app'))->toBeTrue();

    // Mass-delete revoke fires ContextGrantRevoked → the provider listener bumps
    // the durable epoch, orphaning the persistent entry.
    $this->artisan('guard:context:revoke', [
        'user-id' => $user->getAuthIdentifier(),
        'permission' => 'app.posts.edit',
        'panel' => 'app',
        'context-type' => 'workspace',
        'context-id' => 42,
    ])->assertExitCode(0);

    app()->forgetScopedInstances();
    expect($guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app'))->toBeFalse();
});

it('invalidates the cross-request cache when every context grant is revoked with --all', function (): void {
    $user = User::factory()->create();
    $guard = app(ContextGuardContract::class);

    (new ContextGrantBuilder($user))->on('app')->inContext('workspace', 42)->grant('app.posts.edit');
    (new ContextGrantBuilder($user))->on('app')->inContext('workspace', 42)->grant('app.posts.view');

    expect($guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app'))->toBeTrue();

    app()->forgetScopedInstances();
    expect($guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app'))->toBeTrue();

    $this->artisan('guard:context:revoke', [
        'user-id' => $user->getAuthIdentifier(),
        'permission' => 'ignored',
        'panel' => 'app',
        'context-type' => 'workspace',
        'context-id' => 42,
        '--all' => true,
        '--force' => true,
    ])->assertExitCode(0);

    app()->forgetScopedInstances();
    expect($guard->checkInContext($user, 'workspace', 42, 'app.posts.edit', 'app'))->toBeFalse();
});
