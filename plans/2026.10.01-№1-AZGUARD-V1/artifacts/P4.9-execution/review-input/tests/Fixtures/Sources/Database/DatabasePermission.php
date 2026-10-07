<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Database;

use AzGuard\Permissions\PolicyOnly;
use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum DatabasePermission: string
{
    case View = 'documents.view';
    case Edit = 'documents.edit';
    #[PolicyOnly]
    case Policy = 'documents.policy';
}
