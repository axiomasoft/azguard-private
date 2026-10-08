<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;

/**
 * A health check class the reports plugin adds to a panel.
 */
final class ReportsTableExists implements DoctorCheck
{
    public function key(): string
    {
        return 'reports.table';
    }

    public function run(DoctorContext $context): iterable
    {
        return [];
    }
}
