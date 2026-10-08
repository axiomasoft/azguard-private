<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Contracts\Diagnostics\DoctorCheck;

/**
 * A source that brings its own health checks to `azguard:doctor`; they run for each panel the source is attached to.
 *
 * @spi
 */
interface ChecksHealth extends Source
{
    /** @return list<DoctorCheck> */
    public function doctorChecks(): array;
}
