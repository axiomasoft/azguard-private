<?php

declare(strict_types=1);

/*
 * Load bench of AzGuard: php bench/bin/run.php [--profile=all|id,id] [--tier=smoke|mini|ci|ref]
 *     [--driver=sqlite|pgsql|mysql|mariadb] [--cache=none|array|redis] [--reps=N] [--out=path-without-extension]
 *
 * Seeds the deterministic dataset of the tier once, then runs every selected profile: per stage, forked workers
 * record raw samples; 3 repetitions by default, up to 5 while the p95 of an operation varies more than 10%. The
 * result is written as <out>.json and <out>.md (default: bench/results/<date>-<driver>-<cache>-<tier>). The exit
 * code is 1 when a correctness check of a stage fails or an operation reports errors.
 */

use AzGuardBench\Load\Dataset;
use AzGuardBench\Load\ProfileCatalog;
use AzGuardBench\Load\ResultFile;
use AzGuardBench\Load\Runner;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Summary;
use AzGuardBench\Load\Tier;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$options = getopt('', ['profile:', 'tier:', 'driver:', 'cache:', 'reps:', 'out:']);
$option = static fn (string $name, string $default): string => is_string($options[$name] ?? null) ? $options[$name] : $default;
$tier = Tier::named($option('tier', 'mini'));
$driver = $option('driver', 'sqlite');
$cache = $option('cache', 'none');
$reps = (int) $option('reps', (string) $tier->reps);
$out = $option('out', $root.'/bench/results/'.gmdate('Y-m-d').'-'.$driver.'-'.$cache.'-'.$tier->name);
$work = $root.'/bench/.runs/'.getmypid();
$profiles = ProfileCatalog::select($option('profile', 'all'));

$stand = Stand::boot($driver, $cache, $work);
$log = static fn (string $line) => fwrite(STDERR, '['.gmdate('H:i:s').'] '.$line."\n");
$log("seeding tier {$tier->name} on {$driver}");
$t = hrtime(true);
$stand->migrate();
$counts = Dataset::seed($tier);
$log(sprintf('seeded in %.1f s: %s', (hrtime(true) - $t) / 1e9, json_encode($counts)));

$runner = new Runner;
$failed = false;
$results = [];
foreach ($profiles as ['profile' => $profile, 'primary_op' => $primary]) {
    $stages = [];
    foreach ($profile->stages($tier) as $stage) {
        $repetitions = [];
        $windows = [];
        for ($rep = 1; $rep <= 5; $rep++) {
            $profile->prepare($stand, $tier, $stage);
            $run = $runner->run($stand, $tier, $profile, $stage, "{$work}/{$profile->id()}/{$stage->name}/{$rep}");
            $repetitions[] = Summary::repetition($run['samples'], $run['window_s']);
            $windows[] = round($run['window_s'], 3);
            $summary = Summary::across($repetitions);

            if ($rep >= $reps && ($rep >= 5 || Summary::worstCv($summary) <= Summary::STABLE_CV)) {
                break;
            }
        }
        $summary = Summary::across($repetitions);
        $checks = $profile->verify($stand, $tier, $stage);
        foreach ($summary as $op => $s) {
            if ($s['errors'] > 0 || $op === 'error') {
                $checks[] = ['name' => "operation {$op} without errors", 'ok' => false, 'detail' => "{$s['errors']} errors; see bench/.runs"];
            }
        }
        $failed = $failed || in_array(false, array_column($checks, 'ok'), true);
        $stages[] = ['name' => $stage->name, 'workers' => $stage->workers, 'iterations_per_worker' => $stage->iterations, 'warmup_per_worker' => $stage->warmup,
            'windows_s' => $windows, 'reps' => $repetitions, 'summary' => $summary, 'checks' => $checks];
        $primaryStats = $summary[$primary] ?? null;
        $log(sprintf('%s %s: %d reps%s', $profile->id(), $stage->name, count($repetitions), $primaryStats === null ? '' : sprintf(', %s p50 %.0f µs p95 %.0f µs, %.1f ops/s', $primary, $primaryStats['p50_us'], $primaryStats['p95_us'], $primaryStats['ops_per_s'])));
    }
    $results[] = ['id' => $profile->id(), 'description' => $profile->description(), 'primary_op' => $primary, 'stages' => $stages];
}

$git = static fn (string $args): string => trim((string) shell_exec('git -C '.escapeshellarg($root).' '.$args.' 2>/dev/null'));
$cpu = preg_match('/model name\s*:\s*(.+)/', (string) @file_get_contents('/proc/cpuinfo'), $m) === 1 ? trim($m[1]) : php_uname('m');
$database = $stand->database();
$result = [
    'schema' => ResultFile::SCHEMA,
    'date' => gmdate('Y-m-d\TH:i:s\Z'),
    'commit' => $git('rev-parse --short=12 HEAD'),
    'dirty' => $git('status --porcelain -- packages bench') !== '',
    'tier' => $tier->name,
    'driver' => $driver,
    'cache' => $cache,
    'environment' => [
        'php' => PHP_VERSION, 'laravel' => $stand->app->version(), 'database' => $database['driver'], 'database_server' => $database['server'],
        'opcache' => (bool) ini_get('opcache.enable_cli'), 'xdebug' => extension_loaded('xdebug'), 'cpu' => $cpu,
        'cpus' => (int) trim((string) shell_exec('nproc 2>/dev/null')), 'os' => php_uname('s').' '.php_uname('r'),
    ],
    'dataset' => ['tier' => $tier->toArray(), 'rows' => $counts, 'digest' => hash('sha256', json_encode([$tier->toArray(), $counts], JSON_THROW_ON_ERROR))],
    'profiles' => $results,
];
ResultFile::write($result, $out);
$log("written {$out}.json and {$out}.md");
array_map(static fn (string $f) => @unlink($f), glob($work.'/azguard_bench.sqlite*') ?: []);

exit($failed ? 1 : 0);
