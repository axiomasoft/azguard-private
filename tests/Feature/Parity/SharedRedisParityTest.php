<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Visibility\ParityGenerator;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';
require_once __DIR__.'/../../Fixtures/Visibility/ParityGenerator.php';

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('tracks authority generations across all entry points with Redis shared across requests', function (): void {
    $prefix = 'azguard-parity-'.bin2hex(random_bytes(12)).':';
    config(['database.redis.client' => 'phpredis', 'database.redis.options.prefix' => $prefix,
        'database.redis.parity' => ['host' => '127.0.0.1', 'port' => getenv('REDIS_PORT') ?: 26379, 'database' => 15],
        'cache.stores.parity' => ['driver' => 'redis', 'connection' => 'parity', 'prefix' => 'sets:']]);
    $client = app('redis')->connection('parity')->client();

    if ($client->getOption(Redis::OPT_PREFIX) !== $prefix || ! preg_match('/\Aazguard-parity-[0-9a-f]{24}:\z/', $prefix)
        || $client->keys('*') !== []) {
        throw new RuntimeException('Redis parity namespace is not isolated and empty.');
    }

    try {
        ParityGenerator::cacheGenerations('parity');
        expect($client->keys('*'))->not->toBeEmpty();
    } finally {
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
})->group('redis', 'stand', 'server-skip');

it('checks generated facts independently in cold memo and shared Redis lanes', function (): void {
    $prefix = 'azguard-parity-'.bin2hex(random_bytes(12)).':';
    config(['database.redis.client' => 'phpredis', 'database.redis.options.prefix' => $prefix,
        'database.redis.parity' => ['host' => '127.0.0.1', 'port' => getenv('REDIS_PORT') ?: 26379, 'database' => 15],
        'cache.stores.parity' => ['driver' => 'redis', 'connection' => 'parity', 'prefix' => 'sets:']]);
    $client = app('redis')->connection('parity')->client();

    if ($client->getOption(Redis::OPT_PREFIX) !== $prefix || ! preg_match('/\Aazguard-parity-[0-9a-f]{24}:\z/', $prefix)
        || $client->keys('*') !== []) {
        throw new RuntimeException('Redis parity namespace is not isolated and empty.');
    }

    try {
        ParityGenerator::cachedOracle('parity');
        expect($client->keys('*'))->not->toBeEmpty();
    } finally {
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
})->group('redis', 'stand', 'server-skip');
