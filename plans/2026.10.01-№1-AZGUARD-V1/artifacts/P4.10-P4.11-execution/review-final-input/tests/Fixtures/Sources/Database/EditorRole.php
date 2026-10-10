<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Database;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

#[Role('editor')]
final class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [DatabasePermission::View, DatabasePermission::Edit];
    }
}
