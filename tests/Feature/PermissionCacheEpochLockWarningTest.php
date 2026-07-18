<?php

declare(strict_types=1);

use AzGuard\Registry\Resolver\PermissionCache;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * C-05 — forgetForUser()'s epoch bump only serializes concurrent forgets
 * under a lock when the store implements LockProvider; without one, the race
 * documented in PermissionCache degrades silently. It must now warn once per
 * request instead.
 */
final class NoLockStore implements Store
{
    private array $data = [];

    public function get($key)
    {
        return $this->data[$key] ?? null;
    }

    public function many(array $keys)
    {
        return array_map(fn ($key) => $this->get($key), $keys);
    }

    public function put($key, $value, $seconds)
    {
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
        $this->data[$key] = ($this->data[$key] ?? 0) + $value;

        return $this->data[$key];
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, null);
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

it('warns once per request when the epoch bump runs without a lock', function (): void {
    Cache::extend('azguard_no_lock', fn () => new Repository(new NoLockStore));
    config(['cache.stores.azguard_no_lock' => ['driver' => 'azguard_no_lock']]);
    config(['az-guard.cache.store' => 'azguard_no_lock']);

    Log::spy();

    $cache = new PermissionCache;
    $cache->forgetForUser(7, 'app');
    $cache->forgetForUser(7, 'app');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'without a lock'))
        ->once();
});
