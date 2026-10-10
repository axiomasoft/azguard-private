<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Policies\Duo;

use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyFor;
use AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Duo\SecondPermission;

#[PolicyFor(SecondPermission::class)]
final class SecondPolicy
{
    #[Decides(SecondPermission::View)]
    public function allow(): bool
    {
        return true;
    }
}
