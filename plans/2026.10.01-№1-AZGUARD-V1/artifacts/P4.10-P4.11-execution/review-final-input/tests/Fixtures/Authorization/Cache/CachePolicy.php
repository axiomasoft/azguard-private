<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization\Cache;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Panels\User;

final class CachePolicy
{
    public static bool $allow = true;

    public static int $calls = 0;

    #[Decides('documents.view')]
    public function view(User $user, ?object $resource): bool
    {
        self::$calls++;

        return self::$allow && $user->department === 'sales' && ($resource === null || $resource->active);
    }
}
