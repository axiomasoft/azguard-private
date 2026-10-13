<?php

declare(strict_types=1);

namespace App\Guards\Core\Roles;

use App\Guards\Core\Permissions\NotePermission;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/** Granted by hand through `azguard:roles:grant`: the core-only fixture needs no module to do it. */
#[Role('editor')]
final class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [NotePermission::View, NotePermission::Edit];
    }
}
