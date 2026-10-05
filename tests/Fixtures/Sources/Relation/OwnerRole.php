<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Relation;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

#[Role('owner')]
final class OwnerRole extends BaseRole
{
    public function permissions(): array
    {
        return [RelationPermission::View];
    }
}
