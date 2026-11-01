<?php

declare(strict_types=1);

// Compare only matched, independently stable runs. Missing evidence is not a passing comparison.
$args = array_slice($argv, 1);

if (count($args) !== 2) {
    fwrite(STDERR, "usage: php bench/bin/compare.php BASE.json HEAD.json\n");
    exit(2);
}
$read = static function (string $path): array {
    $contents = @file_get_contents($path);
    $value = $contents === false ? null : json_decode($contents, true);

    if (! is_array($value) || ($value['schema'] ?? null) !== 'urn:azguard:bench:result:1') {
        throw new RuntimeException("Invalid result: {$path}");
    }

    return $value;
};

try {
    [$base, $head] = array_map($read, $args);
    foreach (['tier', 'driver', 'cache'] as $key) {
        if (! isset($base[$key], $head[$key]) || $base[$key] !== $head[$key]) {
            throw new RuntimeException("Not comparable: {$key}");
        }
    }
    foreach (['host_id', 'php', 'laravel', 'database', 'database_server', 'opcache', 'xdebug', 'cpu', 'cpus', 'os', 'filament', 'transaction_probe', 'redis_server', 'redis_client'] as $key) {
        if (! isset($base['environment'][$key], $head['environment'][$key]) || $base['environment'][$key] !== $head['environment'][$key] || $base['environment'][$key] === '') {
            throw new RuntimeException("Not comparable: environment.{$key}");
        }
    }

    if (empty($base['dataset']['digest']) || $base['dataset'] !== ($head['dataset'] ?? null)) {
        throw new RuntimeException('Not comparable: dataset');
    }
    $index = static function (array $result): array {
        $out = [];
        foreach ($result['profiles'] ?? [] as $profile) {
            foreach ($profile['stages'] ?? [] as $stage) {
                $key = $profile['id'].'/'.$stage['name'];

                if (isset($out[$key]) || count($stage['reps'] ?? []) < 3 || count($stage['reps']) > 5
                    || empty($stage['summary']) || ! is_array($stage['checks'] ?? null)) {
                    throw new RuntimeException("Incomplete stage {$key}");
                }
                foreach ($stage['checks'] as $check) {
                    if (($check['ok'] ?? false) !== true) {
                        throw new RuntimeException("Failed check in {$key}");
                    }
                }
                $out[$key] = $stage;
            }
        }

        if ($out === []) {
            throw new RuntimeException('No stages to compare');
        }
        ksort($out);

        return $out;
    };
    $before = $index($base);
    $after = $index($head);

    if (array_keys($before) !== array_keys($after)) {
        throw new RuntimeException('Not comparable: stages differ');
    }
    $regressions = 0;
    $matched = 0;
    foreach ($before as $key => $oldStage) {
        $newStage = $after[$key];
        foreach (['workers', 'iterations_per_worker', 'warmup_per_worker'] as $field) {
            if (! isset($oldStage[$field], $newStage[$field]) || $oldStage[$field] !== $newStage[$field]) {
                throw new RuntimeException("Not comparable: {$key}/{$field}");
            }
        }
        $oldOps = $oldStage['summary'];
        $newOps = $newStage['summary'];
        ksort($oldOps);
        ksort($newOps);

        if (array_keys($oldOps) !== array_keys($newOps)) {
            throw new RuntimeException("Not comparable: operations in {$key}");
        }
        foreach ($oldOps as $op => $old) {
            $new = $newOps[$op];
            foreach ([$old, $new] as $sample) {
                if (! is_numeric($sample['p95_us'] ?? null) || ! is_numeric($sample['ops_per_s'] ?? null)
                    || ! is_numeric($sample['p95_cv'] ?? null) || ($sample['errors'] ?? null) !== 0) {
                    throw new RuntimeException("Invalid measurement {$key}/{$op}");
                }
            }
            $complete = static function (array $reps, string $op): bool {
                foreach ($reps as $rep) {
                    if (! is_array($rep) || ! is_numeric($rep[$op]['p95_us'] ?? null)) {
                        return false;
                    }
                }

                return true;
            };
            $stable = $complete($oldStage['reps'], $op) && $complete($newStage['reps'], $op)
                && ($old['stable'] ?? false) === true && ($new['stable'] ?? false) === true
                && $old['p95_cv'] <= 0.10 && $new['p95_cv'] <= 0.10;
            $slower = $new['p95_us'] > $old['p95_us'] * 1.2 && $new['p95_us'] - $old['p95_us'] > 1000;
            $throughput = ! str_starts_with($op, 'probe.') && $old['ops_per_s'] > 0 && $new['ops_per_s'] < $old['ops_per_s'] * 0.8;
            $regression = $stable && ($slower || $throughput);
            $regressions += (int) $regression;
            $matched += (int) $stable;
            printf("%s/%s p95 %.1f -> %.1f us, ops/s %.1f -> %.1f: %s\n", $key, $op,
                $old['p95_us'], $new['p95_us'], $old['ops_per_s'], $new['ops_per_s'],
                $regression ? 'REGRESSION' : ($stable ? 'matched' : 'unstable'));
        }
    }

    if ($matched === 0) {
        throw new RuntimeException('No stable matched operations');
    }
    exit($regressions > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(2);
}
