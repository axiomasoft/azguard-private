<?php

declare(strict_types=1);

use AzGuard\Runtime\ScopedRoleCache;

it('remembers a value and resolves it only once', function (): void {
    $cache = new ScopedRoleCache;
    $calls = 0;

    $resolve = function () use (&$calls): string {
        $calls++;

        return 'value';
    };

    expect($cache->remember('key', $resolve))->toBe('value')
        ->and($cache->remember('key', $resolve))->toBe('value')
        ->and($calls)->toBe(1);
});

it('flush() drops cached values', function (): void {
    $cache = new ScopedRoleCache;
    $cache->remember('key', fn (): int => 1);
    $cache->flush();

    expect($cache->remember('key', fn (): int => 2))->toBe(2);
});

it('is bound as a scoped instance and reset on a new request scope', function (): void {
    $first = app(ScopedRoleCache::class);

    expect($first)->toBeInstanceOf(ScopedRoleCache::class)
        ->and(app(ScopedRoleCache::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(ScopedRoleCache::class))->not->toBe($first);
});
