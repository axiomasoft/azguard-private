<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Testing\Contracts\RestrictionContractTests;
use Closure;
use PHPUnit\Framework\Assert;

/** @mixin Assert */
final class RestrictionProbe
{
    use RestrictionContractTests;
    use RunsAsAProbe;

    /** @param Closure(): Restriction $make */
    public function __construct(private readonly Closure $make) {}

    protected function azguardRestriction(): Restriction
    {
        return ($this->make)();
    }
}
