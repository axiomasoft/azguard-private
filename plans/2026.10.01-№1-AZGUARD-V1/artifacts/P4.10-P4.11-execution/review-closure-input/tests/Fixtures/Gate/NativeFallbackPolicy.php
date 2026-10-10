<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Gate;

use AzGuard\Tests\Fixtures\Panels\User;

final class NativeFallbackPolicy
{
    public function update(User $user, User $record): bool
    {
        return $user->getKey() === $record->getKey();
    }
}
