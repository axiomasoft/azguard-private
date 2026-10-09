<?php

declare(strict_types=1);

/*
 * Compares two bench results: php bench/bin/compare.php BASE.json HEAD.json [--ratio=1.20] [--delta-us=200]
 *
 * For every profile, stage and operation present in both, the head p50 is a regression when it is at least `ratio`
 * times the base p50 and at least `delta-us` slower (both conditions, as the PR rule of chatom bench, so that noise on
 * sub-millisecond operations does not fail). Unstable operations (p95 CV above 10% in either run) are reported but
 * never fail. Exit 1 when a regression is found, 2 when the runs are not comparable (tier, driver or cache differ).
 */

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => ! str_starts_with($a, '--')));
$options = getopt('', ['ratio:', 'delta-us:']);
$ratio = (float) (is_string($options['ratio'] ?? null) ? $options['ratio'] : '1.20');
$delta = (float) (is_string($options['delta-us'] ?? null) ? $options['delta-us'] : '200');

if (count($args) !== 2) {
    fwrite(STDERR, "usage: php bench/bin/compare.php BASE.json HEAD.json [--ratio=1.20] [--delta-us=200]\n");
    exit(2);
}

/** @return array<string, mixed> */
$read = static function (string $file): array {
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) && ($data['schema'] ?? null) === 'urn:azguard:bench:result:1' ? $data : throw new RuntimeException("{$file} is not a bench result.");
};
$base = $read($args[0]);
$head = $read($args[1]);

foreach (['tier', 'driver', 'cache'] as $key) {
    if (($base[$key] ?? null) !== ($head[$key] ?? null)) {
        fwrite(STDERR, "Not comparable: {$key} differs.\n");
        exit(2);
    }
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, array{p50: float, stable: bool}> "profile/stage/op" => summary
 */
$index = static function (array $result): array {
    $list = static fn (mixed $value): array => is_array($value) ? $value : [];
    $out = [];
    foreach ($list($result['profiles'] ?? null) as $profile) {
        $profile = $list($profile);
        foreach ($list($profile['stages'] ?? null) as $stage) {
            $stage = $list($stage);
            $prefix = (is_string($profile['id'] ?? null) ? $profile['id'] : '?').'/'.(is_string($stage['name'] ?? null) ? $stage['name'] : '?');
            foreach ($list($stage['summary'] ?? null) as $op => $summary) {
                $summary = $list($summary);
                $p50 = $summary['p50_us'] ?? null;

                if (is_int($p50) || is_float($p50)) {
                    $out[$prefix.'/'.$op] = ['p50' => (float) $p50, 'stable' => ($summary['stable'] ?? false) === true];
                }
            }
        }
    }

    return $out;
};
$before = $index($base);
$after = $index($head);
$regressions = 0;
printf("%-60s %10s %10s %7s  %s\n", 'operation', 'base p50', 'head p50', 'ratio', 'verdict');
foreach ($after as $key => $now) {
    if (! isset($before[$key])) {
        continue;
    }
    $old = $before[$key]['p50'];
    $new = $now['p50'];
    $r = $old > 0 ? $new / $old : 1.0;
    $stable = $before[$key]['stable'] && $now['stable'];
    $verdict = $r >= $ratio && $new - $old >= $delta ? ($stable ? 'REGRESSION' : 'slower (unstable)') : ($r <= 1 / $ratio && $old - $new >= $delta ? 'faster' : 'same');
    $regressions += $verdict === 'REGRESSION' ? 1 : 0;
    printf("%-60s %8.0fµs %8.0fµs %7.2f  %s\n", $key, $old, $new, $r, $verdict);
}

exit($regressions > 0 ? 1 : 0);
