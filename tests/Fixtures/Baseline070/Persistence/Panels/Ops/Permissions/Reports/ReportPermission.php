<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Baseline070\Persistence\Panels\Ops\Permissions\Reports;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum ReportPermission: string
{
    case View = 'reports.view';
    case Export = 'reports.export';
}
