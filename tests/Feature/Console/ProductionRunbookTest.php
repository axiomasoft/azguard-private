<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Authorization\Internal\Cache\SignedEnvelope;
use AzGuard\Catalog\Internal\CatalogCache;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\FencedCacheSource;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Operations\RecoveryGate;
use AzGuard\Tests\Fixtures\Operations\RunbookEnvironment;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Support\AuditTable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

uses(RunbookEnvironment::class);

beforeEach(function (): void {
    $this->runbookDirectory = dirname(__DIR__, 3).'/build/production-runbook-drill/test.'.bin2hex(random_bytes(8));
    mkdir($this->runbookDirectory, 0700, true);
    config(['azguard.catalog.cache_path' => $this->runbookDirectory.'/catalog.php',
        'azguard.cache.namespace' => 'runbook-test-'.bin2hex(random_bytes(16)),
        'azguard.cache.signing_key' => random_bytes(32), 'azguard.cache.key_id' => 'old',
        'azguard.cache.previous_keys' => [], 'cache.stores.runbook' => ['driver' => 'array', 'serialize' => false]]);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(CatalogCache::class);
    $socket = getenv('RUNBOOK_REDIS_SOCKET');

    if (is_string($socket) && $socket !== '') {
        $socket = realpath($socket);
        $base = realpath(dirname(__DIR__, 3).'/build/production-runbook-drill');

        if ($socket === false || $base === false || ! str_starts_with($socket, $base.'/socket.') || ! str_ends_with($socket, '/redis.sock')) {
            throw new RuntimeException('Refusing a Redis socket outside the disposable drill directory.');
        }
        config(['database.redis.client' => 'phpredis', 'database.redis.options' => ['prefix' => '', 'serializer' => 0, 'compression' => 0],
            'database.redis.runbook' => ['host' => $socket, 'port' => 0, 'database' => 0],
            'cache.stores.runbook' => ['driver' => 'redis', 'connection' => 'runbook', 'prefix' => config('azguard.cache.namespace').':']]);
    }
    CrmWorld::seed();
    AuditTable::create();
});

afterEach(function (): void {
    app()->instance('env', 'testing');
    app('db')->purge('testbench');
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
    RuntimePolicy::$callback = null;
    File::deleteDirectory($this->runbookDirectory);
});

function runbookPanel(): Panel
{
    return W::panel(configure: static fn (PanelBuilder $p) => $p->cache('runbook', 60)->plugins([AuditPlugin::make()]));
}

function runbookDecision(int $client = 1, int $user = 1): Decision
{
    app()->forgetScopedInstances();
    app()->forgetInstance(Authorizer::class);

    return CrmWorld::decide(app(PanelRegistry::class)->get('crm'), client: $client, user: $user);
}

function runbookCommand(string $command, array $arguments = [], ?string &$output = null): int
{
    $exit = Artisan::call($command, $arguments);
    $output = Artisan::output();
    $report = getenv('RUNBOOK_REPORT');

    if (is_string($report) && $report !== '') {
        file_put_contents($report.'/commands.log', $command.' '.json_encode($arguments).' exit='.$exit.PHP_EOL.$output, FILE_APPEND);
    }

    return $exit;
}

it('keeps maintenance closed on wrong config or failed reset and safely restores before warmup', function (): void {
    $live = $this->runbookDirectory.'/authority_test.sqlite';
    $backup = $this->runbookDirectory.'/snapshot_test.sqlite';
    CrmWorld::storage()->connection()->statement('VACUUM INTO ?', [$live]);
    $reconnect = static function () use ($live): void {
        app('db')->purge('testbench');
        config(['database.connections.testbench.database' => $live]);
        app()->forgetInstance(StorageRegistry::class);
        app()->forgetScopedInstances();
    };
    $reconnect();
    $panel = runbookPanel();
    expect(runbookDecision()->allowed())->toBeTrue();
    CrmWorld::storage()->connection()->statement('VACUUM INTO ?', [$backup]);
    $snapshotHash = hash_file('sha256', $backup);
    $audit = CrmWorld::storage()->table('audit_log')->orderBy('id')->get()->toArray();
    $before = CrmWorld::storage()->state('crm');
    W::revoke($panel, 'seller', 1, 1);
    expect(runbookDecision()->reason)->toBe(DecisionReason::NotGranted);
    $cached = CrmWorld::storage()->state('crm');

    // Host ingress/workers are represented by this explicit fence; the package does not run artisan up.
    $gate = new RecoveryGate;
    app('db')->purge('testbench');
    expect(copy($backup, $live))->toBeTrue()->and(hash_file('sha256', $live))->toBe($snapshotHash);
    $reconnect();
    $panel = runbookPanel();
    expect(CrmWorld::storage()->state('crm')->incarnation)->toBe($before->incarnation)
        ->and(CrmWorld::storage()->table('audit_log')->orderBy('id')->get()->toArray())->toEqual($audit);
    $gate->record('restore', true);

    // Negative control: a different post-restore write recreates the external cached denial token.
    W::revoke($panel, 'analyst', 1, 2);
    $collision = CrmWorld::storage()->state('crm');
    expect($collision->incarnation)->toBe($cached->incarnation)->and($collision->epoch)->toBe($cached->epoch)
        ->and(runbookDecision()->reason)->toBe(DecisionReason::NotGranted);
    app()->instance('env', 'production');
    expect(runbookCommand('azguard:state:reset', ['panel' => 'crm']))->toBe(2)
        ->and(runbookCommand('azguard:state:reset', ['panel' => 'unknown', '--force' => true]))->toBe(2)
        ->and(CrmWorld::storage()->state('crm')->incarnation)->toBe($collision->incarnation)
        ->and($gate->maintenance)->toBeTrue();
    $gate->record('reset', false);
    expect(fn () => $gate->open())->toThrow(RuntimeException::class, 'maintenance remains closed');
    expect(runbookCommand('azguard:state:reset', ['panel' => 'crm', '--force' => true]))->toBe(0);
    $after = CrmWorld::storage()->state('crm');
    expect($after->incarnation)->not->toBe($collision->incarnation)->and($after->epoch)->toBeGreaterThan($collision->epoch)
        ->and(CrmWorld::storage()->table('audit_log')->orderByDesc('id')->first())
        ->toMatchObject(['type' => 'panel.touched', 'actor_reason' => 'azguard:state:reset']);
    $gate->record('reset', true);

    // Invalid signing configuration must fail production Doctor, while the host fence stays closed.
    $namespace = config('azguard.cache.namespace');
    config(['azguard.cache.namespace' => null, 'cache.stores.runbook.driver' => 'redis']);
    expect(runbookCommand('azguard:doctor', ['--production' => true, '--json' => true], $doctorOutput))->toBe(1)
        ->and($doctorOutput)->toContain('cache.signing')->and($gate->maintenance)->toBeTrue();
    $gate->record('doctor', false);
    expect(fn () => $gate->open())->toThrow(RuntimeException::class, 'maintenance remains closed');
    config(['azguard.cache.namespace' => $namespace, 'cache.stores.runbook.driver' => getenv('RUNBOOK_REDIS_SOCKET') ? 'redis' : 'array']);
    expect(runbookCommand('azguard:catalog:cache'))->toBe(0);
    runbookPanel(); // Reboot the registry against the new catalog as a restarted worker would.
    expect(runbookDecision()->allowed())->toBeTrue();
    $gate->record('warmup', true);
    expect(runbookCommand('azguard:doctor', ['--production' => true, '--json' => true]))->toBe(0);
    $gate->record('doctor', true);
    expect(runbookDecision()->allowed())->toBeTrue()
        ->and(runbookDecision(client: 4)->allowed())->toBeFalse()
        ->and(runbookDecision(user: 4)->allowed())->toBeFalse()
        ->and(runbookDecision(client: 5)->allowed())->toBeFalse();
    $gate->record('smoke', true);
    $gate->open();
    expect($gate->maintenance)->toBeFalse()->and(hash_file('sha256', $backup))->toBe($snapshotHash);
    // A subsequent bad reset cannot reuse previously green warmup/Doctor/smoke evidence.
    $gate->record('reset', false);
    $gate->record('reset', true);
    expect(fn () => $gate->open())->toThrow(RuntimeException::class, 'maintenance remains closed')
        ->and($gate->maintenance)->toBeTrue();
    expect(runbookCommand('azguard:catalog:clear'))->toBe(0)
        ->and(runbookCommand('azguard:catalog:cache'))->toBe(0);
});

it('rotates signed cache with a bounded previous key and refuses unsigned or retired authority', function (): void {
    $panel = runbookPanel();
    $now = Carbon::now('UTC')->toDateTimeImmutable();
    $store = app('cache')->store('runbook');
    $oldKey = config('azguard.cache.signing_key');
    $put = static fn () => (new PermissionSetCache(app('cache')))->put('rotation', $panel, Volatility::Stable, true, [AuthorizationWorld::grant()], $now);
    $get = static fn ($time) => (new PermissionSetCache(app('cache')))->get('rotation', $panel, Volatility::Stable, true, $time);
    $put();
    $raw = $store->get('rotation');
    expect($get($now))->toHaveCount(1);
    config(['azguard.cache.key_id' => 'new', 'azguard.cache.signing_key' => random_bytes(32),
        'azguard.cache.previous_keys' => ['old' => ['key' => $oldKey, 'valid_until' => $now->modify('+30 seconds')->format('Y-m-d\TH:i:s.u\Z')]]]);
    expect($get($now->modify('+29 seconds')))->toHaveCount(1)
        ->and($get($now->modify('+30 seconds')))->toBeNull();
    $put();
    expect(json_decode($store->get('rotation'), true, flags: JSON_THROW_ON_ERROR)['kid'])->toBe('new');
    config(['azguard.cache.previous_keys' => []]);
    $store->put('rotation', $raw, 60);
    expect($get($now))->toBeNull();
    $store->put('rotation', serialize(['items' => [AuthorizationWorld::grant()], 'validUntil' => $now->modify('+60 seconds')]), 60);
    expect($get($now))->toBeNull()->and(SignedEnvelope::fromConfig(app('config'))->decode('unsigned', 'rotation', $now))->toBeNull();
    // A rotated deployment warms from primary; no unsigned compatibility fallback.
    expect(runbookDecision()->allowed())->toBeTrue();
});

it('reloads denial after unsigned authority and isolates generation changes', function (): void {
    Relation::morphMap(['user' => User::class]);
    $source = new FencedCacheSource;
    $source->reuse = Volatility::Stable;
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, static fn (PanelBuilder $p) => $p->cache('runbook', 60));
    expect($engine->decide($panel, $request)->allowed())->toBeFalse();
    $now = Carbon::now('UTC')->toDateTimeImmutable();
    // Inspect the actual runtime key without relying on the internal key-composition signature.
    $store = app('cache')->store('runbook');

    if ($store->getStore() instanceof ArrayStore) {
        $keys = array_keys($store->getStore()->all(false));
    } else {
        $redisStore = $store->getStore();
        $cursor = '0';
        $keys = [];
        do {
            [$cursor, $batch] = $redisStore->connection()->client()->rawCommand('SCAN', $cursor, 'MATCH', $redisStore->getPrefix().'azguard:sets:v2:*', 'COUNT', 100);
            foreach ($batch as $physical) {
                $keys[] = substr($physical, strlen($redisStore->getPrefix()));
            }
        } while ((string) $cursor !== '0');
    }
    expect($keys)->toHaveCount(1);
    $key = $keys[0];
    $store->put($key, serialize(['items' => [AuthorizationWorld::grant()], 'validUntil' => $now->modify('+60 seconds')]), 60);
    app()->forgetScopedInstances();
    expect($engine->decide($panel, $request)->allowed())->toBeFalse()->and($source->grantReads)->toBe(2);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, static fn (PanelBuilder $p) => $p->cache('runbook', 60, generation: 2));
    expect($engine->decide($panel, $request)->allowed())->toBeFalse()->and($source->grantReads)->toBe(3);
    // A miss with an unavailable source must never accept the forged grant.
    $source->read = static function (): iterable {
        throw new RuntimeException('private-source-outage');
        yield;
    };
    $source->revision++;
    app()->forgetScopedInstances();
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError);
});

it('retires only reviewed persistent Redis keys with bounded SCAN and expiry batches', function (): void {
    $store = app('cache')->store('runbook')->getStore();

    if (! $store instanceof RedisStore) {
        // Local self-test is intentionally not Redis qualification.
        expect($store)->toBeInstanceOf(ArrayStore::class);

        return;
    }
    $redis = $store->connection();
    $retired = $store->getPrefix().'retired:';
    $foreign = $store->getPrefix().'foreign:canary';
    $persistent = $retired.'persistent';
    $finite = $retired.'finite';
    $redis->set($persistent, 'test-only');
    $redis->setex($finite, 30, 'test-only');
    $redis->set($foreign, 'untouched');
    $cursor = '0';
    $inventory = [];
    do {
        [$cursor, $batch] = $redis->client()->rawCommand('SCAN', $cursor, 'MATCH', $retired.'*', 'COUNT', 100);
        foreach ($batch as $key) {
            expect(str_starts_with($key, $retired))->toBeTrue();
            $inventory[$key] = true;
        }
    } while ((string) $cursor !== '0');
    expect(array_keys($inventory))->toContain($persistent, $finite)
        ->and($redis->pttl($persistent))->toBe(-1)->and($redis->pttl($foreign))->toBe(-1);
    // Dry run above has not changed any TTL. Old writers are stopped in this isolated fixture.
    foreach (array_chunk(array_keys($inventory), 100) as $batch) {
        foreach ($batch as $key) {
            if (! str_starts_with($key, $retired)) {
                throw new RuntimeException('Refusing a foreign retirement key.');
            }

            if ($redis->pttl($key) === -1) {
                expect($redis->expire($key, 300))->toBeTrue();
            }
        }
    }
    expect($redis->pttl($persistent))->toBeGreaterThan(0)
        ->and($redis->pttl($persistent))->toBeLessThanOrEqual(300000)
        ->and($redis->pttl($finite))->toBeLessThanOrEqual(30000)
        ->and($redis->get($foreign))->toBe('untouched')->and($redis->pttl($foreign))->toBe(-1);
});

it('observes bounded outage and contract counters and rejects missing monitoring evidence', function (): void {
    Relation::morphMap(['user' => User::class]);
    $samples = [];
    $observe = static function (PanelBuilder $p) use (&$samples): void {
        $p->after(static function (AccessRequest $request, EvaluationContext $context, Decision $decision) use (&$samples): void {
            $samples[] = ['outcome' => $decision->failure()?->value ?? ($decision->allowed() ? 'allow' : 'deny'),
                'reason' => $decision->reason->value,
                'source' => [GeneratedSource::class => 'fixture'][$decision->component ?? ''] ?? 'other'];
        });
    };
    $source = new GeneratedSource(read: static function (): iterable {
        throw new RuntimeException('private-identity-secret');
        yield;
    });
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, $observe);
    expect($engine->decide($panel, $request)->allowed())->toBeFalse();
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource, $observe);
    RuntimePolicy::$callback = static fn () => throw new RuntimeException('private-policy-secret');
    $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'));
    expect($engine->decide($panel, $request)->allowed())->toBeFalse()
        ->and(array_column($samples, 'outcome'))->toBe(['transient', 'contract'])
        ->and(json_encode($samples))->not->toContain('private-identity-secret', 'private-policy-secret');
    foreach (['error', 'empty', 'zero', 'nan', 'fired'] as $case) {
        $result = $case === 'empty' ? [] : [['metric' => [], 'value' => [time(), match ($case) {
            'zero' => '0', 'nan' => 'NaN', default => '1',
        }]]];
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Operations/monitoring-check.php']);
        $process->setInput(json_encode(['status' => $case === 'error' ? 'error' : 'success',
            'data' => ['resultType' => 'vector', 'result' => $result]], JSON_THROW_ON_ERROR));
        expect($process->run())->toBe($case === 'fired' ? 0 : 1);
    }
});
