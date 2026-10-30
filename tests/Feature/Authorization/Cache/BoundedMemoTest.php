<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Authorization\Cache\FencedCacheSource;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;

it('bounds both maps over 10000 subjects and revisions and keeps set and panel LRU aligned', function (): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource);
    $cache = new PermissionSetCache(app('cache'));
    $now = Carbon::now()->toDateTimeImmutable();
    $baseline = memory_get_usage(true);
    for ($i = 0; $i < 10000; $i++) {
        $state = StateToken::of('store', 'admin', 'inc', $i, 1, 'fingerprint', 0, $i);
        $key = PermissionSetCache::key($state, SubjectRef::of('user', $i), TenantRef::global(), [AccessScope::in(TenantRef::global())], 'source', 'primary', 'authority', 1);
        $cache->rememberState('state-'.$i, $state);
        $cache->put($key, $panel, Volatility::Request, false, [AuthorizationWorld::grant()], $now);
    }
    $reflection = new ReflectionClass($cache);
    $sets = $reflection->getProperty('sets')->getValue($cache);
    $panels = $reflection->getProperty('panels')->getValue($cache);
    $states = $reflection->getProperty('states')->getValue($cache);
    expect(count($sets))->toBe(1024)->and(count($states))->toBe(1024)
        ->and(array_keys($sets))->toBe(array_keys($panels))
        ->and(memory_get_usage(true) - $baseline)->toBeLessThan(16 * 1024 * 1024);
});

it('promotes hits and evicts only the least recently used set and state', function (): void {
    config(['azguard.cache.max_memo_entries' => 2, 'azguard.cache.max_state_entries' => 2]);
    [, $panel] = AuthorizationWorld::compile(new CacheSource);
    $cache = new PermissionSetCache(app('cache'));
    $now = Carbon::now()->toDateTimeImmutable();
    $state = StateToken::of('store', 'admin', 'inc', 1, 1, 'fingerprint');
    foreach (['a', 'b'] as $key) {
        $cache->put($key, $panel, Volatility::Request, false, [AuthorizationWorld::grant()], $now);
        $cache->rememberState($key, $state);
    }
    expect($cache->get('a', $panel, Volatility::Request, false, $now))->toHaveCount(1)
        ->and($cache->state('a'))->toBe($state);
    $cache->put('c', $panel, Volatility::Request, false, [AuthorizationWorld::grant()], $now);
    $cache->rememberState('c', $state);
    expect($cache->get('b', $panel, Volatility::Request, false, $now))->toBeNull()
        ->and($cache->state('b'))->toBeNull()
        ->and($cache->get('a', $panel, Volatility::Request, false, $now))->toHaveCount(1)
        ->and($cache->state('a'))->toBe($state);
    $cache->forgetState('a');
    expect($cache->get('a', $panel, Volatility::Request, false, $now))->toHaveCount(1);
});

it('recomputes an evicted state and observes a revoke without discarding unrelated sets', function (): void {
    config(['azguard.cache.max_state_entries' => 1]);
    $source = new FencedCacheSource(direct: [AuthorizationWorld::grant()]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source);
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
    $cache = app(PermissionSetCache::class);
    $now = Carbon::now()->toDateTimeImmutable();
    $cache->put('unrelated', $panel, Volatility::Request, false, [AuthorizationWorld::grant()], $now);
    $source->direct = [];
    $source->revision++;
    $cache->rememberState('evict', StateToken::of('store', 'admin', 'inc', 1, 1, 'fingerprint'));
    expect($engine->decide($panel, $request)->allowed())->toBeFalse()
        ->and($cache->get('unrelated', $panel, Volatility::Request, false, $now))->toHaveCount(1);
});

it('rejects invalid memo limits during configuration validation', function (string $key, mixed $value): void {
    expect(fn () => AzGuardConfig::fromRepository(new Repository(['azguard' => ['cache' => [$key => $value]]])))
        ->toThrow(InvalidConfigurationException::class, 'cache.'.$key);
})->with([
    ['max_memo_entries', 0], ['max_state_entries', 1025], ['max_state_entries', null], ['max_memo_entries', 1.5],
]);

it('does not retain volatile entries', function (): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource);
    $cache = new PermissionSetCache(app('cache'));
    $now = Carbon::now()->toDateTimeImmutable();
    $cache->put('volatile', $panel, Volatility::Volatile, true, [AuthorizationWorld::grant()], $now);
    expect($cache->get('volatile', $panel, Volatility::Request, true, $now))->toBeNull();
});

it('bounds memo in a queue worker and flushes it for the next job', function (): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource);
    $now = Carbon::now()->toDateTimeImmutable();
    $observed = [];
    $jobs = [];
    for ($jobIndex = 0; $jobIndex < 2; $jobIndex++) {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('maxTries', 'retryUntil')->andReturn(null);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldReceive('isDeleted', 'isReleased', 'hasFailed')->andReturn(false);
        $job->shouldReceive('fail', 'release')->byDefault();
        $job->shouldReceive('fire')->once()->andReturnUsing(function () use ($panel, $now, &$observed): void {
            $cache = app(PermissionSetCache::class);
            $observed[] = $cache->get('previous-job', $panel, Volatility::Request, false, $now);
            $baseline = memory_get_usage(true);
            for ($i = 0; $i < 10000; $i++) {
                $cache->rememberState('state-'.$i, StateToken::of('store', 'admin', 'inc', $i, 1, 'fp', 0, $i));
                $cache->put('set-'.$i, $panel, Volatility::Request, false, [], $now);
            }
            $observed[] = memory_get_usage(true) - $baseline;
            $cache->put('previous-job', $panel, Volatility::Request, false, [AuthorizationWorld::grant()], $now);
        });
        $jobs[] = $job;
    }
    $queue = Mockery::mock(Queue::class);
    $queue->shouldReceive('getConnectionName')->andReturn('test');
    $queue->shouldReceive('pop')->twice()->andReturn(...$jobs);
    $manager = Mockery::mock(QueueManager::class);
    $manager->shouldReceive('connection')->andReturn($queue);
    $errors = [];
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->andReturnUsing(function (Throwable $error) use (&$errors): void {
        $errors[] = $error->getMessage();
    });
    $worker = new class($manager, app('events'), $handler, static fn (): bool => false, static fn () => app()->forgetScopedInstances()) extends Worker
    {
        protected function supportsAsyncSignals(): bool
        {
            return false;
        }
    };
    $status = $worker->daemon('test', 'default', new WorkerOptions(memory: 512, maxJobs: 2));
    expect($errors)->toBe([])->and($status)->toBe(0)->and($observed)->toHaveCount(4)
        ->and($observed[0])->toBeNull()->and($observed[2])->toBeNull()
        ->and($observed[1])->toBeLessThan(16 * 1024 * 1024)->and($observed[3])->toBeLessThan(16 * 1024 * 1024);
});
