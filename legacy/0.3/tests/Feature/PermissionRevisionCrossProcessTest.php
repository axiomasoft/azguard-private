<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Facades\AzGuard;
use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use AzGuard\Registry\Resolver\SubjectIdentity;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

function d13RedisOrSkip(): Redis
{
    if (! extension_loaded('redis')) {
        Assert::markTestSkipped('D13 cross-process test requires ext-redis.');
    }

    $redis = new Redis;
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);

    try {
        if (! $redis->connect($host, $port, 1.0) || ! $redis->select(15)) {
            Assert::markTestSkipped("D13 cross-process test requires Redis at {$host}:{$port}, database 15.");
        }
    } catch (RedisException) {
        Assert::markTestSkipped("D13 cross-process test requires Redis at {$host}:{$port}, database 15.");
    }

    return $redis;
}

function d13ConfigureRedis(string $prefix): void
{
    config()->set('database.redis.d13_revision', [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'database' => 15,
    ]);
    config()->set('cache.stores.d13_revision', [
        'driver' => 'redis',
        'connection' => 'd13_revision',
        'lock_connection' => 'd13_revision',
        'prefix' => $prefix,
    ]);
    config()->set('az-guard.cache.store', 'd13_revision');
    config()->set('az-guard.cache.expiration_time', 300);
    app('cache')->forgetDriver('d13_revision');
}

function d13DeleteRedisKeys(Redis $redis, string $prefix): void
{
    $iterator = null;

    while (($keys = $redis->scan($iterator, "*{$prefix}*", 100)) !== false) {
        if ($keys !== []) {
            $redis->del($keys);
        }
    }
}

function d13AssertWorkerDenied(string $database, string $prefix, int $userId, int $revision): void
{
    $workspace = dirname(__DIR__, 2);
    $process = proc_open(
        [PHP_BINARY, $workspace.'/tests/Fixtures/revision-reader-worker.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workspace,
        [
            'D13_SQLITE_DATABASE' => $database,
            'D13_REDIS_PREFIX' => $prefix,
            'D13_USER_ID' => (string) $userId,
            'D13_EXPECTED_REVISION' => (string) $revision,
            'REDIS_HOST' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'REDIS_PORT' => getenv('REDIS_PORT') ?: '6379',
            'DB_CONNECTION' => 'sqlite',
        ],
    );

    expect($process)->not->toBeFalse();
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, "D13 revision reader failed for {$database}:\n{$stdout}\n{$stderr}");
}

it('does not publish a rolled-back allow to a different process when revision 8 is reused', function (): void {
    $redis = d13RedisOrSkip();
    $database = tempnam(sys_get_temp_dir(), 'azguard-d13-');
    expect($database)->not->toBeFalse();
    register_shutdown_function(static function () use ($database): void {
        if (is_file($database)) {
            unlink($database);
        }
    });
    $prefix = 'azguard:d13:'.bin2hex(random_bytes(8)).':';

    try {
        config()->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
        ]);
        DB::purge('testbench');
        expect(Artisan::call('migrate:fresh', ['--database' => 'testbench']))->toBe(0);
        expect(Artisan::call('migrate', [
            '--database' => 'testbench',
            '--path' => dirname(__DIR__).'/database/migrations',
            '--realpath' => true,
        ]))->toBe(0);
        d13ConfigureRedis($prefix);

        $user = User::factory()->create();
        expect(DB::connection('testbench')->getDatabaseName())->toBe($database)
            ->and(DB::connection('testbench')->transactionLevel())->toBe(0);
        $inspection = new PDO('sqlite:'.$database);
        expect((int) $inspection->query('SELECT count(*) FROM users')->fetchColumn())->toBe(1);
        DB::table(Config::permissionStateTable())
            ->where('id', PermissionStateRevision::SINGLETON_ID)
            ->update(['revision' => 7]);
        $subject = SubjectIdentity::fromAuthenticatable($user);
        $cache = new PermissionCache;
        $revisionSevenKey = $cache->keyFor($subject, 'test', revision: 7);
        $revisionEightKey = $cache->keyFor($subject, 'test', revision: 8);

        // Warm the reusable deny at revision 7 before opening the transaction.
        expect($user->hasPermission('test.post.create', 'test'))->toBeFalse()
            ->and(app(PermissionStateRevision::class)->current())->toBe(7)
            ->and(cache()->store('d13_revision')->has($revisionSevenKey))->toBeTrue()
            ->and(cache()->store('d13_revision')->has($revisionEightKey))->toBeFalse();

        $connection = app(PermissionStateRevision::class)->connection();
        $connection->beginTransaction();

        try {
            AzGuard::forUser($user)->on('test')->grant('test.post.create');
            $connection->beginTransaction();
            $connection->commit();

            expect($connection->transactionLevel())->toBe(1)
                ->and(app(PermissionStateRevision::class)->current())->toBe(8)
                ->and($user->hasPermission('test.post.create', 'test'))->toBeTrue()
                ->and(cache()->store('d13_revision')->has($revisionEightKey))->toBeFalse();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        expect(app(PermissionStateRevision::class)->current())->toBe(7);
        expect((int) $inspection->query('SELECT count(*) FROM users')->fetchColumn())->toBe(1);
        expect((int) (new PDO('sqlite:'.$database))->query('SELECT count(*) FROM users')->fetchColumn())->toBe(1);
        d13AssertWorkerDenied($database, $prefix, (int) $user->getKey(), 7);

        // Commit an unrelated grant: the revision number 8 is reused while the
        // rolled-back permission remains absent. A second process must deny it.
        AzGuard::forUser($user)->on('test')->grant('test.post.view');
        expect(app(PermissionStateRevision::class)->current())->toBe(8);
        d13AssertWorkerDenied($database, $prefix, (int) $user->getKey(), 8);

        expect(cache()->store('d13_revision')->has($revisionEightKey))->toBeTrue();
    } finally {
        DB::purge('testbench');
        d13DeleteRedisKeys($redis, $prefix);
    }
});
