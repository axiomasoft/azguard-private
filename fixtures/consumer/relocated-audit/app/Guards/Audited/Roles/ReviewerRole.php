<?php

declare(strict_types=1);

namespace App\Guards\Audited\Roles;

use App\Guards\Audited\Permissions\ReportPermission;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/** Granted through `azguard:roles:grant`: every grant is one journal row of the Audit plugin. */
#[Role('reviewer')]
final class ReviewerRole extends BaseRole
{
    public function permissions(): array
    {
        return [ReportPermission::View, ReportPermission::Export];
    }
}
