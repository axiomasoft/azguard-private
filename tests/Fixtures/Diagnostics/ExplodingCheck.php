<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use RuntimeException;

/**
 * A check registered by class name that throws with connection details in its message, as a driver error would.
 */
final class ExplodingCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'probe.exploding';
    }

    public function run(DoctorContext $context): iterable
    {
        throw new RuntimeException('SQLSTATE[08006] connection to pgsql://azguard:Exploding-Secret-42@db.internal:5432/app failed');
    }
}
