<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Sources;

use AzGuard\Contracts\Sources\Source;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Tests\TestCase;

final class GateSourceContractTest extends TestCase
{
    use SourceContractTests;

    protected function azguardSource(): Source
    {
        return GateSource::make();
    }
}
