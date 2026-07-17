<?php

declare(strict_types=1);

use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Values\PermissionSet;

/**
 * F30: with a persistent store + infinite TTL, `forgetForUser` must evict every
 * context-discriminated entry, not just the base key. A per-user epoch integer
 * is embedded in `keyFor()`; `forgetForUser` increments it so all discriminators
 * are orphaned at once.
 *
 * A fresh `PermissionCache` instance simulates a separate request: the in-memory
 * $requestCache is empty, so resolution falls through to the shared persistent
 * store — exactly the Octane / cross-request scenario the epoch guards against.
 */
beforeEach(function () {
    // A named array-backed store persists across fresh PermissionCache instances,
    // standing in for a real cross-request store (Redis / file) with infinite TTL.
    config()->set('cache.stores.azguard_test', ['driver' => 'array']);
    config()->set('az-guard.cache.store', 'azguard_test');
    config()->set('az-guard.cache.expiration_time', null); // infinite TTL
});

it('serves a cache hit within the same epoch (no gratuitous miss)', function () {
    $calls = 0;
    $resolve = function () use (&$calls): PermissionSet {
        $calls++;

        return PermissionSet::fromKeys(['app.posts.view']);
    };

    // Request 1 — computes and persists to the store.
    (new PermissionCache)->rememberForRequest(7, 'app', $resolve, 'ctx-1');

    // Request 2 (fresh instance, empty request cache) — must hit the store,
    // NOT recompute, because the epoch has not advanced.
    $set = (new PermissionCache)->rememberForRequest(7, 'app', $resolve, 'ctx-1');

    expect($calls)->toBe(1)
        ->and($set->keys())->toBe(['app.posts.view']);
});

it('increments the per-user epoch on forgetForUser', function () {
    $cache = new PermissionCache;

    expect($cache->keyFor(7, 'app'))->toBe('azguard.perms.7.app.v1');

    $cache->forgetForUser(7, 'app');

    expect($cache->keyFor(7, 'app'))->toBe('azguard.perms.7.app.v2');

    $cache->forgetForUser(7, 'app');

    expect($cache->keyFor(7, 'app'))->toBe('azguard.perms.7.app.v3');
});

it('embeds the current epoch into the context-discriminated key', function () {
    $cache = new PermissionCache;

    expect($cache->keyFor(7, 'app', 'ctx-1'))->toBe('azguard.perms.7.app.v1.ctx-1');

    $cache->forgetForUser(7, 'app');

    expect($cache->keyFor(7, 'app', 'ctx-1'))->toBe('azguard.perms.7.app.v2.ctx-1');
});

it('invalidates the context-discriminated branch on forgetForUser, not just the base key', function () {
    $stale = fn (): PermissionSet => PermissionSet::fromKeys(['app.posts.view']);
    $fresh = fn (): PermissionSet => PermissionSet::fromKeys([]); // role changed → nothing

    // Request 1: cache a *contextual* (discriminator) set in the persistent store.
    (new PermissionCache)->rememberForRequest(7, 'app', $stale, 'workspace-42');

    // Sanity: a fresh request still serves the contextual set from the store.
    expect((new PermissionCache)->rememberForRequest(7, 'app', $stale, 'workspace-42')->keys())
        ->toBe(['app.posts.view']);

    // Role change → forget. Must orphan the discriminator entry, not only the base.
    (new PermissionCache)->forgetForUser(7, 'app');

    // Fresh request: the stale contextual set must NO LONGER be served — the new
    // epoch key misses, so the fresh (empty) result is computed instead.
    $after = (new PermissionCache)->rememberForRequest(7, 'app', $fresh, 'workspace-42');

    expect($after->keys())->toBe([]);
});

it('refreshes the epoch key TTL on every forget, not only the first seed', function () {
    // Finite TTL so we can observe expiry via time travel.
    config()->set('az-guard.cache.expiration_time', 100);

    // t0: first revoke seeds+bumps the epoch key (epoch 1 -> 2), TTL starts now
    // (expiresAt = t0+100).
    (new PermissionCache)->forgetForUser(7, 'app');

    // t0+60: a request caches a *stale* PermissionSet under epoch 2, with its
    // own fresh TTL (expiresAt = t0+160) — outliving the epoch key's window.
    $this->travel(60)->seconds();
    (new PermissionCache)->rememberForRequest(7, 'app', fn (): PermissionSet => PermissionSet::fromKeys(['app.posts.view']));

    // t0+90: a second revoke. Pre-fix, `increment()` never refreshes the epoch
    // key's TTL, so it is still on track to expire at t0+100 regardless of this
    // call. Post-fix, this call re-`put()`s it, pushing expiry to (t0+90)+100.
    $this->travel(30)->seconds();
    (new PermissionCache)->forgetForUser(7, 'app');

    // t0+150: past the epoch key's *original* TTL (t0+100). Pre-fix the epoch
    // key has already expired, so this third revoke's `currentEpoch()` read
    // falls back to the default (1) and reseeds epoch 2 — colliding with the
    // still-live stale epoch-2 entry cached above (alive until t0+160).
    // Post-fix the epoch key was refreshed at t0+90 (now expires t0+190), so
    // it is still alive: `currentEpoch()` correctly continues from 3.
    $this->travel(60)->seconds();
    (new PermissionCache)->forgetForUser(7, 'app');

    $set = (new PermissionCache)->rememberForRequest(7, 'app', fn (): PermissionSet => PermissionSet::fromKeys([]));

    // Post-fix: no epoch collision, so the base key is fresh and the callback
    // recomputes the revoked (empty) state. Pre-fix this would instead return
    // the stale ['app.posts.view'] entry served from the collided epoch-2 key.
    expect($set->keys())->toBe([]);
});

it('invalidates every discriminator at once (all contexts) with one forget', function () {
    $stale = fn (): PermissionSet => PermissionSet::fromKeys(['app.posts.view']);
    $fresh = fn (): PermissionSet => PermissionSet::fromKeys([]);

    // Two distinct contexts + the base entry, all in the persistent store.
    (new PermissionCache)->rememberForRequest(9, 'app', $stale);
    (new PermissionCache)->rememberForRequest(9, 'app', $stale, 'ctx-a');
    (new PermissionCache)->rememberForRequest(9, 'app', $stale, 'ctx-b');

    (new PermissionCache)->forgetForUser(9, 'app');

    expect((new PermissionCache)->rememberForRequest(9, 'app', $fresh)->keys())->toBe([])
        ->and((new PermissionCache)->rememberForRequest(9, 'app', $fresh, 'ctx-a')->keys())->toBe([])
        ->and((new PermissionCache)->rememberForRequest(9, 'app', $fresh, 'ctx-b')->keys())->toBe([]);
});
