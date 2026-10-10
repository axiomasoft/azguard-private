<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Relation;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum RelationPermission: string
{
    case View = 'projects.view';
    case Edit = 'projects.edit';
}
