<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics\Guards\Notes\Policies\Notes;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Diagnostics\Guards\Notes\Permissions\Notes\NotePermission;
use AzGuard\Tests\Fixtures\Panels\User;

final class NotePolicy
{
    #[Decides(NotePermission::Publish)]
    public function publish(User $user): bool
    {
        return true;
    }

    /** Public, but decides nothing: the doctor warns about it. */
    public function draft(User $user): bool
    {
        return false;
    }
}
