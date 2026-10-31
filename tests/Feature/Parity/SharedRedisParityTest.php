<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Visibility\AuditAutomaticRole;
use AzGuard\Tests\Fixtures\Visibility\ParityGenerator;
use AzGuard\Tests\Fixtures\Visibility\RedisParity;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\AssertionFailedError;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';
require_once __DIR__.'/../../Fixtures/Visibility/ParityGenerator.php';

beforeEach(fn () => W::seed());
afterEach(function (): void {
    AuditAutomaticRole::$enabled = false;
    W::reset();
});

it('tracks authority generations across all entry points with Redis shared across requests', function (): void {
    RedisParity::run(function (string $store, Redis $client): void {
        ParityGenerator::cacheGenerations($store);
        expect($client->keys('*'))->not->toBeEmpty();
    });
})->group('redis', 'stand', 'server-skip');

it('checks generated facts independently in cold memo and shared Redis lanes', function (): void {
    RedisParity::run(function (string $store, Redis $client): void {
        ParityGenerator::cachedOracle($store);
        expect($client->keys('*'))->not->toBeEmpty();
    });
})->group('redis', 'stand', 'server-skip');

it('names the Redis reproduction file when an oracle fails with a temporary store name', function (): void {
    $seed = getenv('AZGUARD_PROPERTY_SEED');
    putenv('AZGUARD_PROPERTY_SEED=1');
    app('db')->connection()->listen(static function (QueryExecuted $query): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')
            && preg_match('/azg_(permission|role)_grants["`]/', $query->sql) === 1) {
            throw new RuntimeException('Intentional oracle source failure.');
        }
    });

    try {
        expect(fn () => RedisParity::run(static fn (string $store) => ParityGenerator::cachedOracle($store)))
            ->toThrow(AssertionFailedError::class, 'reproduce: AZGUARD_PROPERTY_SEED=1 vendor/bin/pest tests/Feature/Parity/SharedRedisParityTest.php');
    } finally {
        putenv($seed === false ? 'AZGUARD_PROPERTY_SEED' : 'AZGUARD_PROPERTY_SEED='.$seed);
    }
})->group('redis', 'stand', 'server-skip');

it('restores Redis and cache state and cleans only its namespace after an oracle returns or fails', function (bool $fails): void {
    $before = ['database.redis' => config('database.redis'), 'cache.stores' => config('cache.stores'), 'azguard.cache' => config('azguard.cache')];
    $manager = app('redis');
    $connections = $manager->connections();
    $existing = app('cache')->store('array');
    $foreign = new Redis;
    $foreign->connect('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 26379));
    $foreign->select(15);
    $key = 'azguard-parity-foreign-'.bin2hex(random_bytes(12));
    $prefix = null;
    $temporary = null;
    $clientUsed = null;
    $foreign->setex($key, 60, 'preserved');

    try {
        $oracle = function (string $store, Redis $client) use ($fails, &$prefix, &$temporary, &$clientUsed): void {
            $prefix = $client->getOption(Redis::OPT_PREFIX);
            $temporary = $store;
            $clientUsed = $client;
            app('cache')->store($store)->put('probe', 'value', 60);
            AuditAutomaticRole::$enabled = true;
            config(['azguard.cache.namespace' => 'changed-in-oracle']);

            if ($fails) {
                throw new RuntimeException('oracle failed');
            }
        };

        if ($fails) {
            expect(fn () => RedisParity::run($oracle))->toThrow(RuntimeException::class, 'oracle failed');
        } else {
            RedisParity::run($oracle);
        }
        foreach ($before as $name => $value) {
            expect(config($name))->toBe($value);
        }
        expect(app('redis'))->toBe($manager)->and(app('cache')->store('array'))->toBe($existing)
            ->and($manager->connections())->toBe($connections)->and($clientUsed->isConnected())->toBeFalse()
            ->and(AuditAutomaticRole::$enabled)->toBeFalse()
            ->and($foreign->get($key))->toBe('preserved')->and($foreign->keys($prefix.'*'))->toBe([]);
        expect(fn () => app('cache')->store($temporary))->toThrow(InvalidArgumentException::class);
    } finally {
        $foreign->del($key);
        $foreign->close();
    }
})->with([true, false])->group('redis', 'stand', 'server-skip');
