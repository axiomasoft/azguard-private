<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use InvalidArgumentException;

/**
 * The sizes of one dataset tier. Everything the bench seeds and measures is a function of the tier and the seed, so
 * two runs of one tier on one machine compare.
 */
final readonly class Tier
{
    /**
     * @param  non-empty-list<int>  $ladder  worker counts of the concurrent stages, ascending
     */
    public function __construct(
        public string $name,
        public int $users,
        public int $heavyRoles,
        public int $heavyDirect,
        public int $posts,
        public int $tenants,
        public int $membersPerTenant,
        public int $iterations,
        public int $warmup,
        public array $ladder,
        public int $reps,
    ) {}

    public static function named(string $name): self
    {
        return match ($name) {
            // Harness check for the test suite: seconds, not a measurement.
            'smoke' => new self('smoke', 40, 5, 50, 500, 4, 5, 20, 5, [1, 2], 1),
            'mini' => new self('mini', 200, 20, 200, 10_000, 20, 10, 200, 20, [1, 2, 4], 3),
            'ci' => new self('ci', 2_000, 40, 500, 100_000, 200, 20, 1_000, 50, [1, 4, 8], 3),
            'ref' => new self('ref', 20_000, 40, 900, 1_000_000, 1_000, 40, 3_000, 100, [1, 4, 8, 16], 3),
            default => throw new InvalidArgumentException("Unknown tier [{$name}]: smoke, mini, ci or ref."),
        };
    }

    /** The largest worker count of the ladder. */
    public function maxWorkers(): int
    {
        return $this->ladder[count($this->ladder) - 1];
    }

    /** @return array<string, int|string|list<int>> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'users' => $this->users, 'heavy_roles' => $this->heavyRoles, 'heavy_direct' => $this->heavyDirect,
            'posts' => $this->posts, 'tenants' => $this->tenants, 'members_per_tenant' => $this->membersPerTenant,
            'iterations' => $this->iterations, 'warmup' => $this->warmup, 'ladder' => $this->ladder, 'reps' => $this->reps];
    }
}
