<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Policies\Duo;

use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyFor;
use AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Duo\FirstPermission;

#[PolicyFor(FirstPermission::class)]
final class FirstPolicy
{
    #[Decides(FirstPermission::View)]
    public function allow(): bool
    {
        return true;
    }
}
