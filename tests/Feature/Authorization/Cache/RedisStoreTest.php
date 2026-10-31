<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Authorization\Internal\Cache\SignedEnvelope;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\FencedCacheSource;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Carbon::setTestNow();
    Carbon::setTestNow(Carbon::now('UTC'));
    config(['azguard.cache.namespace' => 'redis-qualification', 'azguard.cache.signing_key' => str_repeat('q', 32)]);
    $this->redisPrefix = 'azguard-qualification-'.bin2hex(random_bytes(12)).':';
    config(['database.redis.client' => 'phpredis', 'database.redis.options.prefix' => $this->redisPrefix,
        'database.redis.qualification' => ['host' => '127.0.0.1', 'port' => getenv('REDIS_PORT') ?: 26379, 'database' => 15],
        'cache.stores.qualification' => ['driver' => 'redis', 'connection' => 'qualification', 'prefix' => 'sets:']]);
    $this->redis = app('redis')->connection('qualification');
    expect($this->redisPrefix)->toMatch('/\Aazguard-qualification-[0-9a-f]{24}:\z/')
        ->and($this->redis->client()->getOption(Redis::OPT_PREFIX))->toBe($this->redisPrefix);
    expect((string) $this->redis->ping())->toBeIn(['1', 'PONG', '+PONG']);
    // Refuse a collision before the test can write or cleanup this namespace.
    expect($this->redis->client()->keys('*'))->toBe([]);
    $this->redisNamespaceOwned = true;
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});
afterEach(function (): void {
    // Remove only this run's namespace; never FLUSHDB/shared cache.
    if (isset($this->redis, $this->redisNamespaceOwned)) {
        $client = $this->redis->client();
        $prefix = $client->getOption(Redis::OPT_PREFIX);

        if (! is_string($prefix) || ! isset($this->redisPrefix) || $prefix !== $this->redisPrefix
            || ! preg_match('/\Aazguard-qualification-[0-9a-f]{24}:\z/', $prefix)) {
            throw new RuntimeException('Redis cleanup refused: qualification namespace changed or is invalid.');
        }
        $keys = $client->keys('*');
        // Check the whole result before deleting anything, even if the client changes its prefix behavior.
        foreach ($keys as $key) {
            if (! is_string($key) || ! str_starts_with($key, $prefix)) {
                throw new RuntimeException('Redis cleanup refused: a key lies outside the qualification namespace.');
            }
        }
        foreach ($keys as $key) {
            $client->del(substr($key, strlen($prefix)));
        }
    }
});

it('real Redis serializes raw sets across requests, obeys the query budget, and invalidates independent generations and revocations', function (): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->cache('qualification', 60));
    $first = $engine->decide($panel, DatabaseWorld::request());
    expect($first->allowed())->toBeTrue()->and($this->redis->client()->keys('*'))->not->toBeEmpty();
    app()->forgetScopedInstances();
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    for ($i = 0; $i < 10; $i++) {
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    }
    expect($budget['state'])->toBe(1)->and($budget['grants'])->toBe(0);
    config(['azguard.catalog.build_id' => 'qualification-new-deployment']);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetScopedInstances();
    $budget = CacheWorld::emptyBudget();
    [$deployed, $deployedPanel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->cache('qualification', 60));
    $newBuild = $deployed->decide($deployedPanel, DatabaseWorld::request());
    expect($newBuild->allowed())->toBeTrue()->and($newBuild->state->fingerprint())->not->toBe($first->state->fingerprint())
        ->and($budget['grants'])->toBe(2);
    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $m): void {
        $m->table('permission_grants')->where('panel', 'admin')->delete();
        $m->touch('admin');
    });
    app()->forgetScopedInstances();
    expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
    [$newEngine, $newPanel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->cache('qualification', 60, generation: 2));
    expect($newEngine->decide($newPanel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
})->group('redis', 'stand', 'server-skip');

it('rejects cross-process Redis envelope forgery and reloads the authoritative denial', function (string $attack): void {
    $source = new FencedCacheSource;
    $source->reuse = Volatility::Stable;
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $p) => $p->cache('qualification', 60));
    expect($engine->decide($panel, $request)->allowed())->toBeFalse();
    $keys = $this->redis->client()->keys('*');
    expect($keys)->toHaveCount(1);
    $target = $keys[0];
    $store = app('cache')->store('qualification');
    $raw = $store->get(substr($target, strlen($this->redisPrefix.'sets:')));
    expect($raw)->toBeString()->and(json_decode($raw, true))->toBeArray();
    $bait = $this->redisPrefix.'sets:attacker-bait';
    $producer = new Process([PHP_BINARY, dirname(__DIR__, 3).'/Fixtures/Authorization/Cache/PoisonWorker.php',
        (string) (getenv('REDIS_PORT') ?: 26379), $this->redisPrefix, $target, $bait, 'produce', Carbon::now()->toISOString()]);
    $producer->setInput(str_repeat('q', 32));
    $producer->mustRun();
    expect((new PermissionSetCache(app('cache')))->get('attacker-bait', $panel, Volatility::Stable, true,
        Carbon::now()->toDateTimeImmutable()))->toHaveCount(1);
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/Fixtures/Authorization/Cache/PoisonWorker.php',
        (string) (getenv('REDIS_PORT') ?: 26379), $this->redisPrefix, $target, $bait, $attack]);
    $process->mustRun();
    $forged = $store->get(substr($target, strlen($this->redisPrefix.'sets:')));
    $original = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $baitRaw = $store->get('attacker-bait');
    $baitEntry = json_decode($baitRaw, true, flags: JSON_THROW_ON_ERROR);

    if ($attack === 'unsigned') {
        expect($forged)->toBe(base64_decode($baitEntry['payloadBase64'], true));
    } else {
        $expected = $original;
        match ($attack) {
            'payload' => $expected['payloadBase64'] = $baitEntry['payloadBase64'],
            'key' => $expected = $baitEntry,
            'time' => $expected['validUntilUTC'] = (new DateTimeImmutable($original['validUntilUTC']))->modify('-1 second')->format('Y-m-d\TH:i:s.u\Z'),
            'namespace' => $expected['namespace'] = 'attacker',
        };
        expect(json_decode($forged, true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
    }
    app()->forgetScopedInstances();
    expect($engine->decide($panel, $request)->allowed())->toBeFalse()->and($source->grantReads)->toBe(2)
        ->and($store->get('attacker-bait'))->toBe($baitRaw)
        ->and((new PermissionSetCache(app('cache')))->get('attacker-bait', $panel, Volatility::Stable, true, Carbon::now()->toDateTimeImmutable()))->toHaveCount(1);
})->with(['payload', 'key', 'time', 'namespace', 'unsigned'])->group('redis', 'stand', 'server-skip');

it('bounds the actual Redis PTTL by the nearest grant expiry', function (): void {
    $expires = Carbon::now('UTC')->addSeconds(20);
    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $m) use ($expires): void {
        $m->table('permission_grants')->update(['expires_at' => $expires->format('Y-m-d H:i:s')]);
        $m->touch('admin');
    });
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->cache('qualification', 60));
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    $keys = $this->redis->client()->keys('*');
    expect($keys)->toHaveCount(1);
    $ttl = $this->redis->client()->pttl(substr($keys[0], strlen($this->redisPrefix)));
    expect($ttl)->toBeGreaterThan(15000)->toBeLessThanOrEqual(20000);
})->group('redis', 'stand', 'server-skip');

it('rejects an old Redis envelope copied to the post-revoke key', function (string $change): void {
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check, fn (PanelBuilder $p) => $p->cache('qualification', 60));
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    $client = $this->redis->client();
    $old = $client->keys('*')[0];
    $oldValue = $client->get(substr($old, strlen($this->redisPrefix)));

    if ($change === 'incarnation') {
        app(StorageSchema::class)->drop('default');
        app(StorageSchema::class)->create('default');
        DatabaseWorld::storage()->mutate('admin', static fn () => null);
    } else {
        DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $m) use ($change): void {
            $m->table('permission_grants')->delete();

            if ($change === 'revision') {
                $m->touchSubject('admin', SubjectRef::of('user', 1));
            } else {
                $m->touch('admin');
            }
        });
    }
    app()->forgetScopedInstances();
    expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
    $newKeys = array_values(array_diff($client->keys('*'), [$old]));
    expect($newKeys)->toHaveCount(1);
    $client->setex(substr($newKeys[0], strlen($this->redisPrefix)), 60, $oldValue);
    app()->forgetScopedInstances();
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted)
        ->and($budget['grants'])->toBeGreaterThan(0);
    // A valid replay at the same key before the authority token changes remains valid until expiry.
})->with(['revision', 'epoch', 'incarnation'])->group('redis', 'stand', 'server-skip');

it('does not publish a tentative host transaction grant to Redis', function (): void {
    $storage = DatabaseWorld::storage();
    $storage->mutate('admin', static function (StorageMutation $m): void {
        $m->table('permission_grants')->delete();
        $m->touch('admin');
    });
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->cache('qualification', 60));
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeFalse();
    $client = $this->redis->client();
    $keys = $client->keys('*');
    expect($keys)->toHaveCount(1);
    $raw = $client->get(substr($keys[0], strlen($this->redisPrefix)));
    $cacheKey = substr($keys[0], strlen($this->redisPrefix.'sets:'));
    $signer = SignedEnvelope::fromConfig(app('config'));
    expect($signer)->not->toBeNull()
        ->and($signer->decode(app('cache')->store('qualification')->get($cacheKey), $cacheKey, Carbon::now()->toDateTimeImmutable()))->not->toBeNull();
    expect(function () use ($engine, $panel, $storage, $client, $keys, $raw): void {
        $engine->withinAuthorityTransaction($panel, function () use ($engine, $panel, $storage, $client, $keys, $raw): void {
            $storage->mutate('admin', static function (StorageMutation $m): void {
                $m->table('permission_grants')->insert(DatabaseWorld::row('permission'));
                $m->touch('admin');
            });
            expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue()
                ->and($client->keys('*'))->toBe($keys)
                ->and($client->get(substr($keys[0], strlen($this->redisPrefix))))->toBe($raw);

            throw new RuntimeException('rollback tentative grant');
        });
    })->toThrow(RuntimeException::class, 'rollback tentative grant');
    expect($client->keys('*'))->toBe($keys)
        ->and($client->get(substr($keys[0], strlen($this->redisPrefix))))->toBe($raw);
    expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
})->group('redis', 'stand', 'server-skip');

it('preserves a concurrent Redis replacement when rejecting stale bytes', function (string $initial): void {
    [, $panel] = AuthorizationWorld::compile(new FencedCacheSource, fn (PanelBuilder $p) => $p->cache('qualification', 60));
    $now = Carbon::now()->toDateTimeImmutable();
    $store = app('cache')->store('qualification');
    (new PermissionSetCache(app('cache')))->put('race', $panel, Volatility::Stable, true, [AuthorizationWorld::grant()], $now);
    $replacement = $this->redis->get('sets:race');

    if ($initial === 'missing') {
        $this->redis->del('sets:race');
    } else {
        $this->redis->setex('sets:race', 60, $initial === 'malformed' ? 'broken' : serialize('unsigned'));
    }
    $connection = Mockery::mock(PhpRedisConnection::class, [$this->redis->client()])->makePartial();
    $connection->shouldReceive('get')->once()->with('sets:race')->andReturnUsing(function () use ($replacement) {
        $observed = $this->redis->get('sets:race');
        $this->redis->setex('sets:race', 60, $replacement);

        return $observed;
    });
    $backend = new class(app('redis'), $connection) extends RedisStore
    {
        public function __construct($redis, private readonly PhpRedisConnection $racingConnection)
        {
            parent::__construct($redis, 'sets:', 'qualification');
        }

        public function connection()
        {
            return $this->racingConnection;
        }
    };
    app('cache')->extend('race-test', fn () => app('cache')->repository($backend));
    config(['cache.stores.race' => ['driver' => 'race-test']]);
    [, $racingPanel] = AuthorizationWorld::compile(new FencedCacheSource, fn (PanelBuilder $p) => $p->cache('race', 60));
    expect((new PermissionSetCache(app('cache')))->get('race', $racingPanel, Volatility::Stable, true, $now))->toBeNull()
        ->and($this->redis->get('sets:race'))->toBe($replacement)
        ->and((new PermissionSetCache(app('cache')))->get('race', $panel, Volatility::Stable, true, $now))->toHaveCount(1);
})->with(['missing', 'malformed', 'unsigned'])->group('redis', 'stand', 'server-skip');

it('publishes committed Redis authority and reuses it in a fresh scope', function (): void {
    $storage = DatabaseWorld::storage();
    $storage->mutate('admin', static function (StorageMutation $m): void {
        $m->table('permission_grants')->delete();
        $m->touch('admin');
    });
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->cache('qualification', 60));
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeFalse();
    $before = $this->redis->client()->keys('*');
    $engine->withinAuthorityTransaction($panel, function () use ($engine, $panel, $storage): void {
        $storage->mutate('admin', static function (StorageMutation $m): void {
            $m->table('permission_grants')->insert(DatabaseWorld::row('permission'));
            $m->touch('admin');
        });
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    });
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue()
        ->and(array_diff($this->redis->client()->keys('*'), $before))->toHaveCount(1);
    app()->forgetScopedInstances();
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue()
        ->and($budget['grants'])->toBe(0);
})->group('redis', 'stand', 'server-skip');

it('real Redis rejects absolute expiry in request memo and a new incarnation after restore', function (): void {
    $expires = Carbon::now('UTC')->addSeconds(2);
    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $m) use ($expires): void {
        $m->table('permission_grants')->where('panel', 'admin')->update(['expires_at' => $expires->format('Y-m-d H:i:s')]);
        $m->touch('admin');
    });
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check, fn (PanelBuilder $p) => $p->cache('qualification', 60));
    $before = $engine->decide($panel, DatabaseWorld::request());
    expect($before->allowed())->toBeTrue();
    Carbon::setTestNow($expires);
    expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
    app(StorageSchema::class)->drop('default');
    app(StorageSchema::class)->create('default');
    DatabaseWorld::storage()->mutate('admin', static fn () => null);
    app()->forgetScopedInstances();
    $after = $engine->decide($panel, DatabaseWorld::request());
    expect($after->reason)->toBe(DecisionReason::NotGranted)
        ->and($after->state->incarnation())->not->toBe($before->state->incarnation())
        ->and($after->state->version())->toBeLessThan($before->state->version());
})->group('redis', 'stand', 'server-skip');
