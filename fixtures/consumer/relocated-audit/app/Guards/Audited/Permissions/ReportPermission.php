<?php

declare(strict_types=1);

namespace App\Guards\Audited\Permissions;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum ReportPermission: string
{
    case View = 'reports.view';
    case Export = 'reports.export';
}
