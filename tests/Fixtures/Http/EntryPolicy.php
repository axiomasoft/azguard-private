<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Crm\Models\User;

/** Allows every user to enter unless a test closes the door. */
final class EntryPolicy
{
    public static bool $allow = true;

    #[Decides(EntryPermission::Enter)]
    public function enter(User $user): bool
    {
        return self::$allow;
    }
}
