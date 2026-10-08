<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics\Guards\Notes\Permissions\Notes;

use AzGuard\Permissions\PolicyOnly;
use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum NotePermission: string
{
    case View = 'note.view';
    #[PolicyOnly]
    case Publish = 'note.publish';
}
