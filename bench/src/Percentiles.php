<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use InvalidArgumentException;

/** One percentile definition for every profile: nearest rank over the raw samples (as chatom bench). */
final class Percentiles
{
    /**
     * The `ceil(p/100 × n)`-th element of the sorted samples.
     *
     * @param  list<float>  $values  non-empty
     */
    public static function of(array $values, float $p): float
    {
        if ($values === []) {
            throw new InvalidArgumentException('Percentiles require a non-empty series.');
        }
        sort($values);

        return $values[max(1, (int) ceil($p / 100 * count($values))) - 1];
    }

    /** @param  list<float>  $values  non-empty */
    public static function median(array $values): float
    {
        if ($values === []) {
            throw new InvalidArgumentException('The median requires a non-empty series.');
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Coefficient of variation (population standard deviation / mean); 0 for fewer than two values.
     *
     * @param  list<float>  $values
     */
    public static function cv(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / count($values);

        if ($mean <= 0.0) {
            return 0.0;
        }
        $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / count($values);

        return sqrt($variance) / $mean;
    }
}
