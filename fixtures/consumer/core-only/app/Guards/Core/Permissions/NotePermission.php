<?php

declare(strict_types=1);

namespace App\Guards\Core\Permissions;

use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum NotePermission: string
{
    case View = 'notes.view';
    case Edit = 'notes.edit';
}
