<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use RuntimeException;

/**
 * The result of one run: environment, dataset and, per profile and stage, the per-repetition statistics and their
 * summary. It is checked against the required shape of `schema/result.v1.json` before it is written, then written as
 * JSON and as a Markdown table.
 */
final class ResultFile
{
    public const string SCHEMA = 'urn:azguard:bench:result:1';

    /** @param array<string, mixed> $result */
    public static function write(array $result, string $path): void
    {
        self::validate($result);
        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create [{$dir}].");
        }
        file_put_contents($path.'.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        file_put_contents($path.'.md', self::markdown($result));
    }

    /** @param array<string, mixed> $result */
    public static function validate(array $result): void
    {
        foreach (['schema', 'date', 'commit', 'tier', 'driver', 'cache', 'environment', 'dataset', 'profiles'] as $key) {
            if (! array_key_exists($key, $result)) {
                throw new RuntimeException("The result has no [{$key}].");
            }
        }

        if ($result['schema'] !== self::SCHEMA || ! is_array($result['profiles'])) {
            throw new RuntimeException('The result does not follow '.self::SCHEMA.'.');
        }
        foreach ($result['profiles'] as $profile) {
            if (! is_array($profile) || ! is_string($profile['id'] ?? null) || ! is_array($profile['stages'] ?? null)) {
                throw new RuntimeException('Every profile of the result has an id and stages.');
            }
            foreach ($profile['stages'] as $stage) {
                if (! is_array($stage) || ! is_string($stage['name'] ?? null) || ! is_int($stage['workers'] ?? null) || ! is_array($stage['reps'] ?? null) || ! is_array($stage['summary'] ?? null) || ! is_array($stage['checks'] ?? null)) {
                    throw new RuntimeException('Every stage of the result has a name, workers, reps, a summary and checks.');
                }
            }
        }
    }

    /** @param array<string, mixed> $result */
    public static function markdown(array $result): string
    {
        $env = is_array($result['environment']) ? $result['environment'] : [];
        $text = static fn (mixed $value): string => is_bool($value) ? ($value ? 'on' : 'off') : (is_scalar($value) ? (string) $value : '');
        $out = '# AzGuard bench: '.$text($result['tier']).' tier, '.$text($result['driver']).', cache '.$text($result['cache'])."\n\n";
        $out .= 'Commit '.$text($result['commit']).', '.$text($result['date']).'; PHP '.$text($env['php'] ?? '').', Laravel '.$text($env['laravel'] ?? '')
            .', '.$text($env['database'] ?? '').' '.$text($env['database_server'] ?? '').', '.$text($env['cpu'] ?? '').' ('.$text($env['cpus'] ?? '').' CPUs), OPcache '.$text($env['opcache'] ?? '')."\n\n";
        $out .= "| Profile | Stage | Operation | p50 | p95 | p99 | SQL/op | ops/s | errors | stable |\n|---|---|---|---:|---:|---:|---:|---:|---:|:-:|\n";
        $checks = '';
        foreach (is_array($result['profiles']) ? $result['profiles'] : [] as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            foreach (is_array($profile['stages'] ?? null) ? $profile['stages'] : [] as $stage) {
                if (! is_array($stage)) {
                    continue;
                }
                foreach (is_array($stage['summary'] ?? null) ? $stage['summary'] : [] as $op => $s) {
                    if (! is_array($s)) {
                        continue;
                    }
                    $out .= sprintf("| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |\n", $text($profile['id'] ?? ''), $text($stage['name'] ?? ''), $op,
                        self::human((float) $text($s['p50_us'] ?? 0)), self::human((float) $text($s['p95_us'] ?? 0)), self::human((float) $text($s['p99_us'] ?? 0)),
                        $text($s['sql_per_op'] ?? ''), $text($s['ops_per_s'] ?? ''), $text($s['errors'] ?? ''), ($s['stable'] ?? false) === true ? 'yes' : 'no');
                }
                foreach (is_array($stage['checks'] ?? null) ? $stage['checks'] : [] as $check) {
                    if (is_array($check)) {
                        $checks .= '- '.(($check['ok'] ?? false) === true ? 'PASS' : 'FAIL').' '.$text($profile['id'] ?? '').'/'.$text($stage['name'] ?? '').': '.$text($check['name'] ?? '').' ('.$text($check['detail'] ?? '').")\n";
                    }
                }
            }
        }

        return $out.($checks === '' ? '' : "\nChecks:\n\n".$checks);
    }

    private static function human(float $us): string
    {
        return $us >= 1000 ? round($us / 1000, 2).' ms' : round($us).' µs';
    }
}
