<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidCacheConfigException;
use AzGuard\Support\Config;

/**
 * C-04 — az-guard.cache.expiration_time = null (infinite TTL) is only safe
 * on the in-memory-only 'array'/'null' stores. On a persistent store it
 * leaves PermissionCache's per-user epoch key (and everything it guards)
 * with no expiry, growing the store unbounded — fail fast at boot instead.
 */
it('throws when a persistent store is paired with an infinite TTL', function (): void {
    config([
        'az-guard.cache.store' => 'redis',
        'az-guard.cache.expiration_time' => null,
    ]);

    expect(fn () => Config::assertCacheConfigValid())->toThrow(InvalidCacheConfigException::class);
});

it('is fine with the array store and an infinite TTL', function (): void {
    config([
        'az-guard.cache.store' => 'array',
        'az-guard.cache.expiration_time' => null,
    ]);

    Config::assertCacheConfigValid();

    expect(true)->toBeTrue();
});

it('is fine with a persistent store and an explicit TTL', function (): void {
    config([
        'az-guard.cache.store' => 'redis',
        'az-guard.cache.expiration_time' => 3600,
    ]);

    Config::assertCacheConfigValid();

    expect(true)->toBeTrue();
});
