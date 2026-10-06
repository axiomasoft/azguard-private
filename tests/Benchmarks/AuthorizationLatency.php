<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\LatencyPolicy;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;

putenv('APP_ENV=testing');
$samples = (int) (getenv('AZGUARD_BENCH_SAMPLES') ?: 200);
$warmup = 20;

if ($samples < 100) {
    throw new RuntimeException('At least 100 samples are required for p99.');
}
$rows = [];

try {
    $test = new class('latency') extends TestCase {};
    $app = $test->createApplication();
    CacheEngineWorld::seed();
    DatabaseWorld::storage()->connection()->table('users')->where('id', 1)->update(['department' => 'sales']);
    $connection = DatabaseWorld::storage()->connection();
    $counts = ['state' => 0, 'grants' => 0, 'policy' => 0, 'membership' => 0, 'other' => 0];
    $connection->listen(static function (QueryExecuted $q) use (&$counts): void {
        if (! str_starts_with(strtolower(ltrim($q->sql)), 'select')) {
            return;
        }
        $kind = str_contains($q->sql, 'azg_panel_state') ? 'state'
            : (str_contains($q->sql, 'azg_permission_grants') || str_contains($q->sql, 'azg_role_grants') ? 'grants' : 'other');
        $counts[$kind]++;
    });
    foreach ([1, 10, 100] as $grants) {
        $storage = DatabaseWorld::storage();
        $storage->mutate('admin', static function ($m) use ($grants): void {
            $m->table('permission_grants')->where('panel', 'admin')->delete();
            for ($i = 0; $i < $grants; $i++) {
                $m->table('permission_grants')->insert(DatabaseWorld::row('permission', overrides: ['origin' => 'bench-'.$i]));
            }
            $m->touch('admin');
        });
        foreach ([false, true] as $policy) {
            [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), configure: static function (PanelBuilder $p) use ($policy): void {
                if ($policy) {
                    $p->policies([PolicyBinding::for(DatabasePermission::View, LatencyPolicy::class)]);
                }
            });
            foreach (['cold', 'warm'] as $temperature) {
                $request = DatabaseWorld::request();
                $measure = static function () use ($temperature, $engine, $panel, $request): float {
                    if ($temperature === 'cold') {
                        app()->forgetScopedInstances();
                        app('cache')->store('array')->clear();
                    }
                    $start = hrtime(true);
                    $decision = $engine->decide($panel, $request);

                    if (! $decision->allowed()) {
                        throw new RuntimeException('Benchmark authority unexpectedly denied: '.$decision->reason->value);
                    }

                    return (hrtime(true) - $start) / 1e6;
                };
                for ($i = 0; $i < $warmup; $i++) {
                    $measure();
                }
                $counts = array_fill_keys(array_keys($counts), 0);
                LatencyPolicy::$queries = 0;
                $times = [];
                for ($i = 0; $i < $samples; $i++) {
                    $times[] = $measure();
                }
                $counts['policy'] = LatencyPolicy::$queries;
                $counts['other'] -= $counts['policy'];
                sort($times, SORT_NUMERIC);

                if ($temperature === 'warm' && ($counts['state'] !== 0 || $counts['grants'] !== 0)) {
                    throw new RuntimeException('D44 warmed request exceeds zero authority query budget.');
                }
                $rows[] = ['grants' => $grants, 'policy' => $policy, 'cache' => $temperature,
                    'samples' => $samples, 'warmup' => $warmup,
                    'p95_ms' => $times[(int) ceil(.95 * $samples) - 1], 'p99_ms' => $times[(int) ceil(.99 * $samples) - 1],
                    'sql_total' => $counts];
            }
            // Existing persistent sets, new request: exactly one state and zero grant queries for ten checks.
            app()->forgetScopedInstances();
            $counts = array_fill_keys(array_keys($counts), 0);
            LatencyPolicy::$queries = 0;
            for ($i = 0; $i < 10; $i++) {
                $measure();
            }

            if ($counts['state'] !== 1 || $counts['grants'] !== 0 || LatencyPolicy::$queries !== ($policy ? 10 : 0)) {
                throw new RuntimeException('D44 new warmed request exceeded authority/live policy budget.');
            }
        }
    }
    $cpu = preg_match('/model name\s*:\s*(.+)/', (string) @file_get_contents('/proc/cpuinfo'), $m) ? $m[1] : php_uname('m');
    echo json_encode(['php' => PHP_VERSION, 'laravel' => $app->version(), 'driver' => $connection->getDriverName(),
        'database_version' => $connection->selectOne($connection->getDriverName() === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v')->v,
        'hardware' => ['cpu' => $cpu, 'os' => php_uname(), 'memory' => @file_get_contents('/proc/meminfo')],
        'method' => 'One sequential process; monotonic hrtime; nearest-rank percentiles; setup excluded; live policy SQL and membership (absent=0) separate; no latency threshold.',
        'd44' => 'warm request=0 state/0 grants; new warm request x10=1 state/0 grants; policy x10=10 queries',
        'matrix' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
    $failed = true;
} finally {
    if (isset($app)) {
        try {
            CacheEngineWorld::clean();
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'Cleanup failed: '.$cleanupError::class.': '.$cleanupError->getMessage()."\n");
            $failed = true;
        }
    }
}

if (isset($failed)) {
    exit(1);
}
