<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity\Dataset\Panels\Ops\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Parity\Dataset\Panels\Ops\Permissions\Reports\ReportPermission;

#[Role('operator')]
final class OperatorRole extends BaseRole
{
    public function permissions(): array
    {
        return [ReportPermission::View, ReportPermission::Export];
    }
}
