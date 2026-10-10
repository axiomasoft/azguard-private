<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity;

/**
 * The reproducible draw of `ParityPropertyTest` (P14), extracted so the parity matrix generates its worlds the same
 * way: one linear congruential sequence per seed, so a seed printed by a failure reproduces the world exactly.
 */
final class Draw
{
    private int $next;

    public function __construct(public readonly int $seed)
    {
        $this->next = $seed;
    }

    /** A value in `[0, $max)`. */
    public function __invoke(int $max): int
    {
        $this->next = ($this->next * 1664525 + 1013904223) & 0xFFFFFFFF;

        return ($this->next >> 8) % $max;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    public function pick(array $values): mixed
    {
        return $values[$this(count($values))];
    }
}
