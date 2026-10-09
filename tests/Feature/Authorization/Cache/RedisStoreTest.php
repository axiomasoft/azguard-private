<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow();
    Carbon::setTestNow(Carbon::now('UTC'));
    $this->redisPrefix = 'azguard-qualification-'.bin2hex(random_bytes(12)).':';
    config(['database.redis.client' => 'phpredis', 'database.redis.options.prefix' => $this->redisPrefix,
        'database.redis.qualification' => ['host' => '127.0.0.1', 'port' => getenv('REDIS_PORT') ?: 26379, 'database' => 15],
        'cache.stores.qualification' => ['driver' => 'redis', 'connection' => 'qualification', 'prefix' => 'sets:']]);
    $this->redis = app('redis')->connection('qualification');
    expect((string) $this->redis->ping())->toBeIn(['1', 'PONG', '+PONG']);
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
});
afterEach(function (): void {
    // Remove only this run's namespace; never FLUSHDB/shared cache.
    if (isset($this->redis)) {
        $client = $this->redis->client();
        $keys = $client->keys('*');
        $prefix = $client->getOption(Redis::OPT_PREFIX);
        foreach ($keys as $key) {
            if (str_starts_with($key, $prefix)) {
                $client->del(substr($key, strlen($prefix)));
            }
        }
    }
});

it('real Redis serializes raw sets across requests, obeys D44, and invalidates independent generations and revocations', function (): void {
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
    expect($newBuild->allowed())->toBeTrue()->and($newBuild->state->fingerprint)->not->toBe($first->state->fingerprint)
        ->and($budget['grants'])->toBe(2);
    DatabaseWorld::storage()->mutate('admin', static function (StorageMutation $m): void {
        $m->table('permission_grants')->where('panel', 'admin')->delete();
        $m->touch('admin');
    });
    app()->forgetScopedInstances();
    expect($engine->decide($panel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
    [$newEngine, $newPanel] = CacheWorld::database(DatabaseSource::make(), configure: fn (PanelBuilder $p) => $p->cache('qualification', 60, generation: 2));
    expect($newEngine->decide($newPanel, DatabaseWorld::request())->reason)->toBe(DecisionReason::NotGranted);
})->group('redis', 'stand');

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
        ->and($after->state->incarnation)->not->toBe($before->state->incarnation)
        ->and($after->state->version)->toBeLessThan($before->state->version);
})->group('redis', 'stand');
