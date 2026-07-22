<?php

declare(strict_types=1);

use AzGuard\Registry\Resolver\PermissionCache;

const P4_REDIS_CONNECTION = 'p4_race';
const P4_REDIS_STORE = 'p4_race';
const P4_RACE_WORKERS = 8;
const P4_RACE_ITERATIONS = 3;

/** @return array{host: string, port: int, database: int} */
function p4RedisConnection(): array
{
    return [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', '6379'),
        'database' => 15,
    ];
}

function configureP4RedisCache(string $prefix): void
{
    $connection = p4RedisConnection();

    config()->set('database.redis.'.P4_REDIS_CONNECTION, $connection);
    config()->set('cache.stores.'.P4_REDIS_STORE, [
        'driver' => 'redis',
        'connection' => P4_REDIS_CONNECTION,
        'lock_connection' => P4_REDIS_CONNECTION,
        'prefix' => $prefix,
    ]);
    config()->set('az-guard.cache.store', P4_REDIS_STORE);
    config()->set('az-guard.cache.expiration_time', 300);
    app('cache')->forgetDriver(P4_REDIS_STORE);
}

function p4RedisOrSkip(): Redis
{
    if (! extension_loaded('redis')) {
        test()->markTestSkipped('P4.4 Redis race test skipped: ext-redis is not installed.');
    }

    $connection = p4RedisConnection();
    $redis = new Redis;

    try {
        if (! $redis->connect($connection['host'], $connection['port'], 1.0)) {
            test()->markTestSkipped(sprintf(
                'P4.4 Redis race test skipped: Redis is unavailable at %s:%d.',
                $connection['host'],
                $connection['port'],
            ));
        }

        $redis->select($connection['database']);
    } catch (RedisException) {
        test()->markTestSkipped(sprintf(
            'P4.4 Redis race test skipped: Redis is unavailable at %s:%d.',
            $connection['host'],
            $connection['port'],
        ));
    }

    return $redis;
}

function deleteP4RedisKeys(Redis $redis, string $prefix): void
{
    $iterator = null;

    while (($keys = $redis->scan($iterator, "{$prefix}*", 100)) !== false) {
        if ($keys !== []) {
            $redis->del($keys);
        }
    }
}

it('serializes concurrent epoch bumps across real Redis processes', function (): void {
    $redis = p4RedisOrSkip();
    $token = bin2hex(random_bytes(8));
    $prefix = "azguard:p4-race:{$token}:";
    $userId = 4404;
    $panelId = 'p4-race';

    configureP4RedisCache($prefix);
    deleteP4RedisKeys($redis, $prefix);

    $epochKey = "azguard.perms.{$userId}.{$panelId}.epoch";
    $environment = [
        'P4_RACE_WORKER' => '1',
        'P4_REDIS_HOST' => p4RedisConnection()['host'],
        'P4_REDIS_PORT' => (string) p4RedisConnection()['port'],
        'P4_REDIS_DATABASE' => (string) p4RedisConnection()['database'],
        'P4_REDIS_PREFIX' => $prefix,
        'P4_RACE_USER_ID' => (string) $userId,
        'P4_RACE_PANEL_ID' => $panelId,
        'P4_RACE_ITERATIONS' => (string) P4_RACE_ITERATIONS,
    ];
    $workspace = dirname(__DIR__, 2);

    try {
        $processes = [];

        for ($worker = 0; $worker < P4_RACE_WORKERS; $worker++) {
            $process = proc_open(
                [
                    PHP_BINARY,
                    $workspace.'/vendor/bin/pest',
                    $workspace.'/tests/Fixtures/epoch-bump-worker.php',
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $workspace,
                $environment,
            );

            expect($process)->not->toBeFalse();

            fclose($pipes[0]);
            $processes[] = compact('process', 'pipes');
        }

        foreach ($processes as $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);

            expect(proc_close($worker['process']))
                ->toBe(0, "Redis epoch worker failed:\n{$stdout}\n{$stderr}");
        }

        $expectedEpoch = 1 + (P4_RACE_WORKERS * P4_RACE_ITERATIONS);

        expect((int) cache()->store(P4_REDIS_STORE)->get($epochKey))
            ->toBe($expectedEpoch)
            ->and((new PermissionCache)->keyFor($userId, $panelId))
            ->toBe("azguard.perms.{$userId}.{$panelId}.v{$expectedEpoch}");

        // One more real bump proves the completed sequence did not roll the value back.
        (new PermissionCache)->forgetForUser($userId, $panelId);

        expect((int) cache()->store(P4_REDIS_STORE)->get($epochKey))
            ->toBe($expectedEpoch + 1);
    } finally {
        deleteP4RedisKeys($redis, $prefix);
    }
});
