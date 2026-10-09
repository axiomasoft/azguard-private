<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

/** Statistics of raw samples: per operation and repetition, then across repetitions. */
final class Summary
{
    /** A repetition is unstable when the p95 of an operation varies more than this across repetitions. */
    public const float STABLE_CV = 0.10;

    /**
     * @param  list<array{op: string, us: float, sql: int, ok: bool, t: int}>  $samples
     * @return array<string, array{count: int, errors: int, p50_us: float, p95_us: float, p99_us: float, max_us: float, sql_per_op: float, ops_per_s: float}>
     */
    public static function repetition(array $samples, float $window): array
    {
        $byOp = [];
        foreach ($samples as $sample) {
            $byOp[$sample['op']][] = $sample;
        }
        ksort($byOp);
        $stats = [];
        foreach ($byOp as $op => $list) {
            $times = array_map(static fn (array $s): float => $s['us'], $list);
            $stats[$op] = [
                'count' => count($list),
                'errors' => count(array_filter($list, static fn (array $s): bool => ! $s['ok'])),
                'p50_us' => round(Percentiles::of($times, 50), 1),
                'p95_us' => round(Percentiles::of($times, 95), 1),
                'p99_us' => round(Percentiles::of($times, 99), 1),
                'max_us' => round(max($times), 1),
                'sql_per_op' => round(array_sum(array_map(static fn (array $s): int => $s['sql'], $list)) / count($list), 2),
                'ops_per_s' => $window > 0 ? round(count($list) / $window, 1) : 0.0,
            ];
        }

        return $stats;
    }

    /**
     * The median of every statistic across repetitions, with the variation of p95.
     *
     * @param  list<array<string, array{count: int, errors: int, p50_us: float, p95_us: float, p99_us: float, max_us: float, sql_per_op: float, ops_per_s: float}>>  $reps
     * @return array<string, array{p50_us: float, p95_us: float, p99_us: float, sql_per_op: float, ops_per_s: float, errors: int, p95_cv: float, stable: bool}>
     */
    public static function across(array $reps): array
    {
        $ops = [];
        foreach ($reps as $rep) {
            foreach ($rep as $op => $stats) {
                $ops[$op][] = $stats;
            }
        }
        ksort($ops);
        $summary = [];
        foreach ($ops as $op => $list) {
            $column = static fn (string $key): array => array_map(static fn (array $s): float => (float) $s[$key], $list);
            $cv = Percentiles::cv($column('p95_us'));
            $summary[$op] = [
                'p50_us' => round(Percentiles::median($column('p50_us')), 1),
                'p95_us' => round(Percentiles::median($column('p95_us')), 1),
                'p99_us' => round(Percentiles::median($column('p99_us')), 1),
                'sql_per_op' => round(Percentiles::median($column('sql_per_op')), 2),
                'ops_per_s' => round(Percentiles::median($column('ops_per_s')), 1),
                'errors' => (int) array_sum($column('errors')),
                'p95_cv' => round($cv, 3),
                'stable' => $cv <= self::STABLE_CV,
            ];
        }

        return $summary;
    }

    /** @param  array<string, array{p95_cv: float}>  $summary */
    public static function worstCv(array $summary): float
    {
        // Probe samples (lock wait, snapshot span) describe the stage; they do not decide its repetitions.
        $ops = array_filter($summary, static fn (string $op): bool => ! str_starts_with($op, 'probe.'), ARRAY_FILTER_USE_KEY);

        return $ops === [] ? 0.0 : max(array_map(static fn (array $s): float => $s['p95_cv'], $ops));
    }
}
