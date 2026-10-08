<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics\Guards\Notes\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Diagnostics\Guards\Notes\Permissions\Notes\NotePermission;

#[Role('editor')]
final class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [NotePermission::View];
    }
}
