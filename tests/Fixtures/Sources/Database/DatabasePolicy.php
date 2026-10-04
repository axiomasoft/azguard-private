<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Database;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Panels\User;

final class DatabasePolicy
{
    #[Decides('documents.policy')]
    public function check(User $user): bool
    {
        return true;
    }
}
