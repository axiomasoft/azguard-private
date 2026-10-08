<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Contracts\Sources\ChecksHealth;

/**
 * A source that brings its own doctor checks.
 */
final readonly class HealthSource implements ChecksHealth
{
    /** @param list<DoctorCheck> $checks */
    public function __construct(private array $checks, private string $id = 'health') {}

    public function id(): string
    {
        return $this->id;
    }

    public function doctorChecks(): array
    {
        return $this->checks;
    }
}
