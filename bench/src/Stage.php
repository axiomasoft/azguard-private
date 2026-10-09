<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

/** One measured configuration of a profile: how many workers run how many iterations each. */
final readonly class Stage
{
    public function __construct(public string $name, public int $workers, public int $iterations, public int $warmup) {}
}
