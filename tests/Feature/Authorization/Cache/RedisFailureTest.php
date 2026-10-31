<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->prefix = 'azguard-qualification-'.bin2hex(random_bytes(12)).':';
    config(['azguard.cache.namespace' => 'redis-failure', 'azguard.cache.signing_key' => str_repeat('f', 32),
        'database.redis.client' => 'phpredis', 'database.redis.options.prefix' => $this->prefix,
        'database.redis.failure' => ['host' => '127.0.0.1', 'port' => getenv('REDIS_PORT') ?: 26379, 'database' => 15],
        'cache.stores.failure' => ['driver' => 'failure-test']]);
    $this->redis = app('redis')->connection('failure');
    expect($this->redis->client()->getOption(Redis::OPT_PREFIX))->toBe($this->prefix)
        ->and($this->redis->client()->keys('*'))->toBe([]);
    $this->owned = true;
    $this->faultStore = new QualificationFaultStore(app('redis'), 'sets:', 'failure');
    $faultStore = $this->faultStore;
    app('cache')->extend('failure-test', static fn () => app('cache')->repository($faultStore));
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});

afterEach(function (): void {
    if (isset($this->owned)) {
        $client = $this->redis->client();
        $prefix = $client->getOption(Redis::OPT_PREFIX);

        if ($prefix !== $this->prefix || ! preg_match('/\Aazguard-qualification-[0-9a-f]{24}:\z/', $prefix)) {
            throw new RuntimeException('Redis cleanup refused: invalid prefix.');
        }
        $keys = $client->keys('*');
        foreach ($keys as $key) {
            if (! str_starts_with($key, $prefix)) {
                throw new RuntimeException('Redis cleanup refused: key outside namespace.');
            }
        }
        foreach ($keys as $key) {
            $client->del(substr($key, strlen($prefix)));
        }
    }
});

it('reads authoritative grants after a Redis operation fails', function (string $operation): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->cache('failure', 60));

    if ($operation === 'forget') {
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
        $key = $this->redis->client()->keys('*')[0];
        $this->redis->client()->setex(substr($key, strlen($this->prefix)), 60, serialize('unsigned'));
        app()->forgetScopedInstances();
    }
    $this->faultStore->fail = $operation;
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue()
        ->and($this->faultStore->failures)->toBeGreaterThan(0)->and($budget['grants'])->toBeGreaterThan(0);
})->with(['get', 'put', 'forget'])->group('redis', 'stand', 'server-skip');

it('fails closed before consulting a warm cache when authoritative state is unavailable', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->cache('failure', 60));
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    app()->forgetScopedInstances();
    $this->faultStore->fail = 'get';
    app(StorageSchema::class)->drop('default');
    $decision = $engine->decide($panel, DatabaseWorld::request());
    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::SourceError)
        ->and($this->faultStore->failures)->toBe(0);
})->group('redis', 'stand', 'server-skip');

it('does not reuse a warm allow after a confirmed revoke during a Redis disconnect', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check, fn (PanelBuilder $p) => $p->cache('failure', 60));
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $m): void {
        $m->table('permission_grants')->delete();
        $m->touch('admin');
    });
    app()->forgetScopedInstances();
    $this->faultStore->fail = 'get';
    $decision = $engine->decide($panel, DatabaseWorld::request());
    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::NotGranted)
        ->and($this->faultStore->failures)->toBeGreaterThan(0);
})->group('redis', 'stand', 'server-skip');

it('falls back to the database on a real Redis read timeout', function (): void {
    config(['cache.stores.timeout-test' => ['driver' => 'redis', 'connection' => 'failure', 'prefix' => 'sets:']]);
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->cache('timeout-test', 60));
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $m): void {
        $m->table('permission_grants')->delete();
        $m->touch('admin');
    });
    $server = new Process([PHP_BINARY, dirname(__DIR__, 3).'/Fixtures/Authorization/Cache/RedisTimeoutServer.php']);
    $server->start();

    try {
        expect($server->waitUntil(static fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "\n")))->toBeTrue();
        $port = (int) strtok($server->getOutput(), "\n");
        expect($port)->toBeGreaterThan(0);
        config(['database.redis.timeout-test' => ['host' => '127.0.0.1', 'port' => $port, 'database' => 15,
            'timeout' => 0.1, 'read_timeout' => 0.1, 'retry_interval' => 0],
            'cache.stores.timeout-test.connection' => 'timeout-test']);
        app()->forgetInstance('redis');
        app('cache')->purge('timeout-test');
        app()->forgetScopedInstances();
        $budget = CacheWorld::emptyBudget();
        CacheWorld::listen($budget);
        $decision = $engine->decide($panel, DatabaseWorld::request());
        expect($decision->reason)->toBe(DecisionReason::NotGranted)
            ->and($budget['grants'])->toBeGreaterThan(0)
            ->and($server->getOutput())->toContain('"command":"GET"', 'azguard:sets:v2:');
    } finally {
        $server->stop(0.1);
    }
})->group('redis', 'stand', 'server-skip');

/** Simulates a disconnect at a specific Redis operation, preserving the real store for other operations. */
final class QualificationFaultStore extends RedisStore
{
    public string $fail = '';

    public int $failures = 0;

    private ?PhpRedisConnection $faultConnection = null;

    private function fault(string $operation): void
    {
        if ($this->fail === $operation) {
            $this->failures++;

            throw new RedisException('simulated Redis disconnect');
        }
    }

    public function connection()
    {
        return $this->faultConnection ??= new class(parent::connection()->client(), fn (string $operation) => $this->fault($operation)) extends PhpRedisConnection
        {
            public function __construct($client, private readonly Closure $fault)
            {
                parent::__construct($client);
            }

            public function get($key)
            {
                ($this->fault)('get');

                return parent::get($key);
            }

            public function eval($script, $numberOfKeys, ...$arguments)
            {
                ($this->fault)('forget');

                return parent::eval($script, $numberOfKeys, ...$arguments);
            }
        };
    }

    public function put($key, $value, $seconds)
    {
        $this->fault('put');

        return parent::put($key, $value, $seconds);
    }

    public function forget($key)
    {
        $this->fault('forget');

        return parent::forget($key);
    }
}
