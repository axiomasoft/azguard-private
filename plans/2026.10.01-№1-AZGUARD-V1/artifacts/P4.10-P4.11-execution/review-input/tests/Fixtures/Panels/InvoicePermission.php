<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum InvoicePermission: string
{
    case View = 'invoices.view';
}
