<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Visibility;

use Closure;
use Illuminate\Redis\RedisManager;
use Redis;
use RuntimeException;

/** A private Redis manager and namespace, restored even when setup, an oracle or cleanup fails. */
final class RedisParity
{
    /** @param Closure(string, Redis): void $oracle */
    public static function run(Closure $oracle): void
    {
        $prefix = 'azguard-parity-'.bin2hex(random_bytes(12)).':';
        $store = 'parity-'.bin2hex(random_bytes(12));
        $original = ['cache.stores' => config('cache.stores'), 'azguard.cache' => config('azguard.cache')];
        $enabled = AuditAutomaticRole::$enabled;
        $previous = app('redis');
        $manager = new RedisManager(app(), 'phpredis', [
            'options' => ['prefix' => $prefix],
            'parity' => ['host' => '127.0.0.1', 'port' => getenv('REDIS_PORT') ?: 26379, 'database' => 15],
        ]);
        $client = null;

        try {
            app()->instance('redis', $manager);
            config(['cache.stores.'.$store => ['driver' => 'redis', 'connection' => 'parity', 'prefix' => 'sets:']]);
            $client = $manager->connection('parity')->client();

            if ($client->getOption(Redis::OPT_PREFIX) !== $prefix || $client->keys('*') !== []) {
                throw new RuntimeException('Redis parity namespace is not isolated and empty.');
            }
            $oracle($store, $client);
        } finally {
            try {
                if ($client !== null) {
                    if ($client->getOption(Redis::OPT_PREFIX) !== $prefix) {
                        throw new RuntimeException('Redis cleanup refused: namespace changed.');
                    }
                    $keys = $client->keys('*');
                    foreach ($keys as $key) {
                        if (! is_string($key) || ! str_starts_with($key, $prefix)) {
                            throw new RuntimeException('Redis cleanup refused: key outside namespace.');
                        }
                    }
                    foreach ($keys as $key) {
                        $client->del(substr($key, strlen($prefix)));
                    }
                }
            } finally {
                app('cache')->purge($store);
                app()->instance('redis', $previous);
                config($original);
                AuditAutomaticRole::$enabled = $enabled;
                foreach ($manager->connections() as $connection) {
                    $connection->disconnect();
                }
                $manager->purge('parity');
            }
        }
    }
}
