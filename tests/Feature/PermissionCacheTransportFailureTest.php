<?php

declare(strict_types=1);

use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Tests\Support\IdlePermissionStateRevision;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;

final class ThrowingPermissionCacheStore implements Store
{
    public bool $throwOnGet = false;

    public bool $throwOnPut = false;

    /** @var array<string, mixed> */
    private array $data = [];

    public function get($key)
    {
        if ($this->throwOnGet) {
            throw new RuntimeException('cache get failed');
        }

        return $this->data[$key] ?? null;
    }

    public function many(array $keys)
    {
        return array_map(fn ($key) => $this->get($key), $keys);
    }

    public function put($key, $value, $seconds)
    {
        if ($this->throwOnPut) {
            throw new RuntimeException('cache put failed');
        }

        $this->data[$key] = $value;

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        $this->data[$key] = ((int) ($this->data[$key] ?? 0)) + $value;

        return $this->data[$key];
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    public function touch($key, $seconds)
    {
        return true;
    }

    public function forget($key)
    {
        unset($this->data[$key]);

        return true;
    }

    public function flush()
    {
        $this->data = [];

        return true;
    }

    public function getPrefix()
    {
        return '';
    }
}

function throwingPermissionCache(ThrowingPermissionCacheStore $store): PermissionCache
{
    Cache::extend('azguard_throwing', fn () => new Repository($store));
    config(['cache.stores.azguard_throwing' => ['driver' => 'azguard_throwing']]);
    config(['az-guard.cache.store' => 'azguard_throwing']);

    return new PermissionCache(permissionState: new IdlePermissionStateRevision);
}

it('recomputs from sources when cache get throws and does not return a durable stale allow', function (): void {
    $store = new ThrowingPermissionCacheStore;
    $cache = throwingPermissionCache($store);
    $subject = permissionCacheTestSubject(3);

    $cache->rememberForRequest($subject, 'app', fn (): PermissionSet => PermissionSet::fromKeys(['app.posts.view']));

    $store->throwOnGet = true;
    $fresh = new PermissionCache(permissionState: new IdlePermissionStateRevision);

    $set = $fresh->rememberForRequest($subject, 'app', fn (): PermissionSet => PermissionSet::fromKeys(['app.posts.edit']));

    expect($set->keys())->toBe(['app.posts.edit']);
});

it('does not persist when cache put throws and still returns the current set', function (): void {
    $store = new ThrowingPermissionCacheStore;
    $store->throwOnPut = true;
    $cache = throwingPermissionCache($store);
    $subject = permissionCacheTestSubject(4);

    $set = $cache->rememberForRequest($subject, 'app', fn (): PermissionSet => PermissionSet::fromKeys(['app.posts.view']));

    expect($set->keys())->toBe(['app.posts.view']);

    $store->throwOnPut = false;
    $again = (new PermissionCache(permissionState: new IdlePermissionStateRevision))
        ->rememberForRequest($subject, 'app', fn (): PermissionSet => PermissionSet::fromKeys(['app.posts.edit']));

    expect($again->keys())->toBe(['app.posts.edit']);
});

it('propagates a source exception instead of treating it as a cache miss', function (): void {
    $store = new ThrowingPermissionCacheStore;
    $cache = throwingPermissionCache($store);
    $subject = permissionCacheTestSubject(5);

    expect(fn () => $cache->rememberForRequest($subject, 'app', function (): PermissionSet {
        throw new RuntimeException('grant source failed');
    }))->toThrow(RuntimeException::class, 'grant source failed');
});

it('misses a request and durable entry after deployment generation changes', function (): void {
    $cache = new PermissionCache(permissionState: new IdlePermissionStateRevision);
    $subject = permissionCacheTestSubject(6);
    $calls = 0;

    $cache->rememberForRequest($subject, 'app', function () use (&$calls): PermissionSet {
        $calls++;

        return PermissionSet::fromKeys(['app.posts.view']);
    });

    $before = $cache->keyFor($subject, 'app');
    config(['az-guard.cache.generation' => 2]);

    $set = $cache->rememberForRequest($subject, 'app', function () use (&$calls): PermissionSet {
        $calls++;

        return PermissionSet::fromKeys(['app.posts.edit']);
    });

    expect($cache->keyFor($subject, 'app'))->not->toBe($before)
        ->and($set->keys())->toBe(['app.posts.edit'])
        ->and($calls)->toBe(2);
});
