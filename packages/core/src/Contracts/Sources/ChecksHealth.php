<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * A source that brings its own health checks to `azguard:doctor`; they run for each panel the source is attached to.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ChecksHealth extends Source
{
    /** @return list<DoctorCheck> */
    public function doctorChecks(): array;
}
