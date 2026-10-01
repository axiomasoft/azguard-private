<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Posts\Policies;

use AzGuard\Attributes\GateAbility;
use AzGuard\Tests\Stubs\Posts\Permissions\PostPermission;
use AzGuard\Tests\Stubs\User;

final class PostPolicy
{
    #[GateAbility(permission: PostPermission::View)]
    public function canView(User $user): bool
    {
        return $user->hasPermission(permission: 'test.post.view');
    }
}
