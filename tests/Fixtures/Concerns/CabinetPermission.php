<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum CabinetPermission: string
{
    case View = 'invoices.view';
    case Pay = 'invoices.pay';
}
