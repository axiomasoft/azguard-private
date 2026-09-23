<?php

declare(strict_types=1);
use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Resolver\SubjectIdentity;
use AzGuard\Tests\TestCase;

if (getenv('P4_RACE_WORKER') === '1') {
    uses(TestCase::class);

    it('bumps a shared Redis permission-cache epoch', function (): void {
        $connection = 'p4_race';
        $store = 'p4_race';

        config()->set("database.redis.{$connection}", [
            'host' => getenv('P4_REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('P4_REDIS_PORT') ?: 6379),
            'database' => (int) (getenv('P4_REDIS_DATABASE') ?: 15),
        ]);
        config()->set("cache.stores.{$store}", [
            'driver' => 'redis',
            'connection' => $connection,
            'lock_connection' => $connection,
            'prefix' => (string) getenv('P4_REDIS_PREFIX'),
        ]);
        config()->set('az-guard.cache.store', $store);
        config()->set('az-guard.cache.expiration_time', 300);
        app('cache')->forgetDriver($store);

        $cache = new PermissionCache;
        $iterations = (int) (getenv('P4_RACE_ITERATIONS') ?: 1);
        $subject = SubjectIdentity::fromPersisted(
            (string) (getenv('P4_RACE_MORPH_TYPE') ?: 'azguard.p4-race-subject'),
            (int) getenv('P4_RACE_USER_ID'),
        );

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $cache->forgetForUser($subject, (string) getenv('P4_RACE_PANEL_ID'));
        }

        expect($iterations)->toBeGreaterThan(0);
    });
}
