<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization\Cache;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Panels\User;

final class LatencyPolicy
{
    public static int $queries = 0;

    #[Decides('documents.view')]
    public function allows(User $user): bool
    {
        self::$queries++;

        return User::query()->whereKey($user->getKey())->where('department', 'sales')->exists();
    }
}
