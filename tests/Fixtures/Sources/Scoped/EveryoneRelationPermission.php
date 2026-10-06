<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Scoped;

use AzGuard\Permissions\GrantedToAll;
use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum EveryoneRelationPermission: string
{
    #[GrantedToAll]
    case View = 'projects.view';
    case Edit = 'projects.edit';
}
