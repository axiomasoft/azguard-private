<?php

declare(strict_types=1);

use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;

/**
 * Records the call order around `forgetForUser`'s epoch bump so the T6 test
 * below can assert the add()/increment()/put() sequence runs INSIDE a lock's
 * block() callback, not before/after it. Wraps a real ArrayStore (which
 * implements LockProvider) rather than faking lock semantics.
 */
final class PermissionCacheLockSpyStore implements LockProvider, Store
{
    /** @var list<string> */
    public static array $log = [];

    public function __construct(private readonly ArrayStore $inner) {}

    public function get($key)
    {
        return $this->inner->get($key);
    }

    public function many(array $keys)
    {
        return $this->inner->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        self::$log[] = "put:{$key}";

        return $this->inner->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds)
    {
        return $this->inner->putMany($values, $seconds);
    }

    public function increment($key, $value = 1)
    {
        self::$log[] = "increment:{$key}";

        return $this->inner->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->inner->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        return $this->inner->forever($key, $value);
    }

    public function touch($key, $seconds)
    {
        return $this->inner->touch($key, $seconds);
    }

    public function forget($key)
    {
        return $this->inner->forget($key);
    }

    public function flush()
    {
        return $this->inner->flush();
    }

    public function getPrefix()
    {
        return $this->inner->getPrefix();
    }

    /**
     * Duck-typed: `Illuminate\Cache\Repository::add()` calls this directly
     * (via `method_exists`) instead of its own get/put fallback when the
     * store defines it — mirrors the real add() contract (put iff absent).
     */
    public function add($key, $value, $seconds)
    {
        self::$log[] = "add:{$key}";

        if (! is_null($this->inner->get($key))) {
            return false;
        }

        return $this->inner->put($key, $value, $seconds);
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        self::$log[] = "lock:{$name}";

        return new PermissionCacheLockSpy($this->inner->lock($name, $seconds, $owner));
    }

    public function restoreLock($name, $owner)
    {
        return new PermissionCacheLockSpy($this->inner->restoreLock($name, $owner));
    }
}

final class PermissionCacheLockSpy implements LockContract
{
    public function __construct(private readonly LockContract $inner) {}

    public function get($callback = null)
    {
        return $this->inner->get($callback);
    }

    public function block($seconds, $callback = null)
    {
        PermissionCacheLockSpyStore::$log[] = 'block:start';

        $result = $this->inner->block($seconds, function () use ($callback) {
            PermissionCacheLockSpyStore::$log[] = 'block:callback';

            return $callback ? $callback() : null;
        });

        PermissionCacheLockSpyStore::$log[] = 'block:end';

        return $result;
    }

    public function release()
    {
        return $this->inner->release();
    }

    public function owner()
    {
        return $this->inner->owner();
    }

    public function forceRelease()
    {
        $this->inner->forceRelease();
    }
}

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
    PermissionCacheLockSpyStore::$log = [];

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

/**
 * T6: `forgetForUser`'s add()/increment()/put() sequence must run serialized
 * under a lock, not as three independent read-modify-write calls — otherwise
 * two concurrent forgets can interleave their trailing `put()`s and roll the
 * epoch backward (see PermissionCache.php forgetForUser docblock).
 *
 * A real cross-process race can't be driven from a single-process test (no
 * portable mechanism). Instead this proves the LOCK WRAPPING exists: `lock()`
 * is acquired on the `{epoch}:lock` key before the bump, and all three store
 * calls happen strictly inside the lock's `block()` callback.
 */
it('bumps the epoch under a lock, with add/increment/put inside block()', function () {
    Cache::extend('spy_array', fn () => new Repository(new PermissionCacheLockSpyStore(new ArrayStore)));
    config()->set('cache.stores.azguard_spy', ['driver' => 'spy_array']);
    config()->set('az-guard.cache.store', 'azguard_spy');
    // Finite TTL so `put()` (not `forever()`) is the call under test — mirrors
    // the production default (Config::cacheTtl() defaults to 3600).
    config()->set('az-guard.cache.expiration_time', 3600);

    PermissionCacheLockSpyStore::$log = [];

    (new PermissionCache)->forgetForUser(7, 'app');

    expect(PermissionCacheLockSpyStore::$log)->toBe([
        'lock:azguard.perms.7.app.epoch:lock',
        'block:start',
        'block:callback',
        'add:azguard.perms.7.app.epoch',
        'increment:azguard.perms.7.app.epoch',
        'put:azguard.perms.7.app.epoch',
        'block:end',
    ]);
});
