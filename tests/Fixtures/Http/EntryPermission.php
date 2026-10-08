<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http;

use AzGuard\Permissions\PolicyOnly;

/** Entering the CRM decided by a policy alone, with no resource. */
enum EntryPermission: string
{
    #[PolicyOnly]
    case Enter = 'interface.enter';
}
