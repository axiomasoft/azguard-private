<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Panels\Policies;

use AzGuard\Attributes\GateAbility;
use AzGuard\Tests\Stubs\Panels\Permissions\AdminPermission;
use AzGuard\Tests\Stubs\User;

final class AdminPostPolicy
{
    #[GateAbility(permission: AdminPermission::PostView)]
    public function view(User $user): bool
    {
        return $user->hasPermission('admin.post.view', 'admin');
    }
}
