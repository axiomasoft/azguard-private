<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use AzGuard\Contracts\Sources\Source;
use AzGuard\Testing\Contracts\SourceContractTests;
use Closure;
use PHPUnit\Framework\Assert;

/** @mixin Assert */
final class SourceProbe
{
    use RunsAsAProbe;
    use SourceContractTests;

    /** @param Closure(): Source $make */
    public function __construct(private readonly Closure $make) {}

    protected function azguardSource(): Source
    {
        return ($this->make)();
    }
}
