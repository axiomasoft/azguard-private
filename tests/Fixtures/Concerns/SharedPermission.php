<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Permissions\RequiresGrant;

/** Attached to both fixture panels: without a named panel it is ambiguous. */
#[RequiresGrant]
enum SharedPermission: string
{
    case Export = 'reports.export';
}
