<?php

declare(strict_types=1);
use AzGuardBench\Load\BenchSqliteConnection;
use AzGuardBench\Load\Profiles\CacheChurn;
use AzGuardBench\Load\Profiles\LockContention;
use AzGuardBench\Load\ResultFile;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use AzGuardBench\Load\TransactionProbe;

function compareAuditResults(array $base, array $head): int
{
    $directory = sys_get_temp_dir().'/azguard-comparison-'.bin2hex(random_bytes(8));
    mkdir($directory);
    file_put_contents($directory.'/base.json', json_encode($base, JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/head.json', json_encode($head, JSON_THROW_ON_ERROR));

    try {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 3).'/bench/bin/compare.php').' '.escapeshellarg($directory.'/base.json').' '.escapeshellarg($directory.'/head.json').' 2>&1', $output, $exit);

        return $exit;
    } finally {
        unlink($directory.'/base.json');
        unlink($directory.'/head.json');
        rmdir($directory);
    }
}

function auditComparisonResult(): array
{
    return [
        'schema' => 'urn:azguard:bench:result:1', 'tier' => 'ci', 'driver' => 'sqlite', 'cache' => 'none',
        'environment' => ['host_id' => 'host', 'php' => '8.4', 'laravel' => '13', 'database' => 'sqlite',
            'database_server' => '3', 'opcache' => true, 'xdebug' => false, 'cpu' => 'cpu', 'cpus' => 4, 'os' => 'linux', 'filament' => false, 'transaction_probe' => false,
            'redis_server' => 'none', 'redis_client' => 'none'],
        'dataset' => ['digest' => 'dataset'],
        'profiles' => [['id' => 'sample', 'stages' => [['name' => 'w1', 'workers' => 1, 'iterations_per_worker' => 100,
            'warmup_per_worker' => 10, 'reps' => array_fill(0, 3, ['check' => ['p95_us' => 5000]]), 'checks' => [['ok' => true]],
            'summary' => ['check' => ['p95_us' => 5000, 'ops_per_s' => 100, 'p95_cv' => 0.05, 'stable' => true, 'errors' => 0]]]]]],
    ];
}

it('only gates regressions for stable comparable results', function (): void {
    $base = auditComparisonResult();
    $head = $base;
    $sample = &$head['profiles'][0]['stages'][0]['summary']['check'];
    expect(compareAuditResults($base, $head))->toBe(0);
    $sample['p95_us'] = 6000; // The policy uses strict inequalities.
    expect(compareAuditResults($base, $head))->toBe(0);
    $sample['p95_us'] = 6001;
    expect(compareAuditResults($base, $head))->toBe(1);
    $sample['p95_us'] = 5000;
    $sample['ops_per_s'] = 79;
    expect(compareAuditResults($base, $head))->toBe(1);
    $sample['stable'] = false;
    expect(compareAuditResults($base, $head))->toBe(2);
});

it('refuses different hosts, insufficient repetitions and failed checks', function (): void {
    $base = auditComparisonResult();
    $head = $base;
    $head['environment']['host_id'] = 'other';
    expect(compareAuditResults($base, $head))->toBe(2);
    $head = $base;
    unset($head['environment']['host_id']);
    expect(compareAuditResults($base, $head))->toBe(2);
    $head = $base;
    unset($head['environment']['transaction_probe']);
    expect(compareAuditResults($base, $head))->toBe(2);
    $head = $base;
    $head['environment']['filament'] = true;
    expect(compareAuditResults($base, $head))->toBe(2);
    $head = $base;
    array_pop($head['profiles'][0]['stages'][0]['reps']);
    expect(compareAuditResults($base, $head))->toBe(2);
    $head = $base;
    $head['profiles'][0]['stages'][0]['reps'][0] = [];
    expect(compareAuditResults($base, $head))->toBe(2);
    $head = $base;
    $head['profiles'][0]['stages'][0]['checks'][0]['ok'] = false;
    expect(compareAuditResults($base, $head))->toBe(2);
});

it('accepts the legacy result shape but refuses to compare an unproven host', function (): void {
    $legacy = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Bench/legacy-result.json'), true, flags: JSON_THROW_ON_ERROR);
    ResultFile::validate($legacy);
    expect(compareAuditResults($legacy, $legacy))->toBe(2);
});

it('refuses a remote redis host before the bench application starts', function (string $host): void {
    $previous = getenv('REDIS_HOST');
    putenv('REDIS_HOST='.$host);

    try {
        expect(fn () => Stand::boot('sqlite', 'redis', '/tmp/unused'))
            ->toThrow(InvalidArgumentException::class, 'local Redis host');
    } finally {
        $previous === false ? putenv('REDIS_HOST') : putenv('REDIS_HOST='.$previous);
    }
})->with(['example.com', 'redis']);

it('runs the bounded churn profile and emits worker memory measurements', function (): void {
    $out = sys_get_temp_dir().'/azguard-churn-test-'.bin2hex(random_bytes(6));
    $cmd = escapeshellarg(PHP_BINARY).' -d memory_limit=1G '.escapeshellarg(dirname(__DIR__, 3).'/bench/bin/run.php')
        .' --tier=smoke --profile=cache-churn --reps=1 --out='.escapeshellarg($out).' 2>&1';
    exec($cmd, $output, $exit);

    try {
        expect($exit)->toBe(0, implode("\n", $output));
        $result = json_decode((string) file_get_contents($out.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $stage = $result['profiles'][0]['stages'][0];
        $measurement = $stage['measurements'][0][0];
        expect($stage['iterations_per_worker'])->toBe(10)
            ->and($stage['checks'][0]['ok'])->toBeTrue()
            ->and($measurement['rss_peak_bytes'])->toBeGreaterThanOrEqual($measurement['rss_start_bytes'])
            ->and($measurement['max_request_sets'])->toBe(1024)
            ->and($measurement['max_request_states'])->toBe(1024);
    } finally {
        @unlink($out.'.json');
        @unlink($out.'.md');
    }
});

it('rejects exceeded request-map and RSS bounds', function (int $sets, int $states, int $growth, int $setLimit, int $stateLimit): void {
    $check = new ReflectionMethod(CacheChurn::class, 'assertBounds');
    expect(fn () => $check->invoke(null, $sets, $states, $growth, $setLimit, $stateLimit))->toThrow(RuntimeException::class, 'bound exceeded');
})->with([[1025, 0, 0, 1024, 1024], [0, 1025, 0, 1024, 1024], [0, 0, 134217729, 1024, 1024]]);

it('accepts keys that expired during scanning but rejects keys without a TTL', function (): void {
    $ttl = new ReflectionMethod(CacheChurn::class, 'finiteTtl');
    expect($ttl->invoke(null, -2))->toBeTrue()
        ->and($ttl->invoke(null, 2))->toBeTrue()
        ->and($ttl->invoke(null, -1))->toBeFalse()
        ->and($ttl->invoke(null, 3))->toBeFalse();
});

it('runs visibility at and beyond the witness budget', function (): void {
    $out = sys_get_temp_dir().'/azguard-branches-test-'.bin2hex(random_bytes(6));
    $cmd = escapeshellarg(PHP_BINARY).' -d memory_limit=1G '.escapeshellarg(dirname(__DIR__, 3).'/bench/bin/run.php')
        .' --tier=smoke --profile=visibility-branches --reps=1 --out='.escapeshellarg($out).' 2>&1';
    exec($cmd, $output, $exit);

    try {
        expect($exit)->toBe(0, implode("\n", $output))->and(is_file($out.'.json'))->toBeTrue();
        $result = json_decode((string) file_get_contents($out.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $stages = $result['profiles'][0]['stages'];
        expect(array_column($stages, 'name'))->toBe(['branches-999', 'branches-1000', 'branches-10000'])
            ->and($stages[0]['summary'])->toHaveKey('visible')
            ->and($stages[1]['summary'])->toHaveKey('visible')
            ->and($stages[2]['summary'])->toHaveKey('overlimit');
    } finally {
        @unlink($out.'.json');
        @unlink($out.'.md');
    }
});

it('reports contention probes and rollback diagnostics', function (): void {
    $out = sys_get_temp_dir().'/azguard-contention-test-'.bin2hex(random_bytes(6));
    $cmd = escapeshellarg(PHP_BINARY).' -d memory_limit=1G '.escapeshellarg(dirname(__DIR__, 3).'/bench/bin/run.php')
        .' --tier=smoke --profile=lock-contention --reps=1 --out='.escapeshellarg($out).' 2>&1';
    exec($cmd, $output, $exit);

    try {
        expect($exit)->toBe(0, implode("\n", $output))->and(is_file($out.'.json'))->toBeTrue();
        $result = json_decode((string) file_get_contents($out.'.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($result['profiles'][0]['stages'] as $stage) {
            expect($stage['checks'][0]['ok'])->toBeTrue()
                ->and($stage['summary'])->toHaveKeys(['probe.lock_wait', 'probe.lock_hold'])
                ->and($stage['measurements'][0][0])->toHaveKeys(['transaction_rollbacks', 'uncaught_deadlocks', 'uncaught_timeouts', 'retries', 'deadlocks', 'timeouts']);
        }
    } finally {
        @unlink($out.'.json');
        @unlink($out.'.md');
    }
});

it('counts failed transactions that are retried and classifies their causes', function (): void {
    require_once dirname(__DIR__, 3).'/bench/src/InstrumentedConnections.php';
    $connection = new BenchSqliteConnection(new PDO('sqlite::memory:'));
    $probe = TransactionProbe::class;
    $probe::reset();
    $probe::begin();
    foreach (['deadlock found', 'lock wait timeout'] as $message) {
        try {
            $connection->transaction(static fn () => throw new RuntimeException($message));

            throw new LogicException('The test transaction must fail.');
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe($message);
        }
    }
    $connection->transaction(static fn () => null);
    $probe::end(true);
    expect($probe::counts())->toMatchArray(['retries' => 2, 'deadlocks' => 1, 'timeouts' => 1, 'other_failures' => 0]);
    $probe::begin();

    try {
        $connection->transaction(static fn () => throw new RuntimeException('failure'));
    } catch (RuntimeException) {
        $probe::end(false);
    }
    expect($probe::counts())->toMatchArray(['retries' => 2, 'other_failures' => 1]);
});

it('covers every requested contention writer and reader combination', function (): void {
    $profile = new LockContention;
    $names = array_map(static fn ($stage) => $stage->name, $profile->stages(Tier::named('mini')));
    expect($names)->toHaveCount(27);
    foreach (['disjoint', 'hot-subject', 'hot-panel'] as $scenario) {
        foreach ([1, 4, 8] as $writers) {
            foreach ([1, 8, 16] as $readers) {
                expect($names)->toContain("{$scenario}:writers{$writers}:readers{$readers}");
            }
        }
    }
});

it('preserves each writer state on a shared subject', function (): void {
    $fixture = dirname(__DIR__, 2).'/Fixtures/Bench/contention.php';
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=1G '.escapeshellarg($fixture).' 2>&1', $output, $exit);
    expect($exit)->toBe(0, implode("\n", $output));
});

it('fails the run when a worker fails in warmup', function (): void {
    $fixture = dirname(__DIR__, 2).'/Fixtures/Bench/warmup-failure.php';
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture).' 2>&1', $output, $exit);
    expect($exit)->toBe(0, implode("\n", $output));
});

it('runs the policy-heavy Filament resource count and page', function (): void {
    $out = sys_get_temp_dir().'/azguard-filament-test-'.bin2hex(random_bytes(6));
    $cmd = escapeshellarg(PHP_BINARY).' -d memory_limit=1G '.escapeshellarg(dirname(__DIR__, 3).'/bench/bin/run.php')
        .' --tier=smoke --profile=filament-pagination --reps=1 --out='.escapeshellarg($out).' 2>&1';
    exec($cmd, $output, $exit);

    try {
        expect($exit)->toBe(0, implode("\n", $output))->and(is_file($out.'.json'))->toBeTrue();
        $result = json_decode((string) file_get_contents($out.'.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($result['profiles'][0]['stages'] as $stage) {
            expect($stage['checks'][0]['ok'])->toBeTrue()->and($stage['summary'])->toHaveKeys(['count', 'page']);
        }
    } finally {
        @unlink($out.'.json');
        @unlink($out.'.md');
    }
});
