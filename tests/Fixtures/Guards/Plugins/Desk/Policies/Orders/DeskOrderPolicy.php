<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Plugins\Desk\Policies\Orders;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Guards\Plugins\Desk\Permissions\Orders\DeskOrderPermission;

final class DeskOrderPolicy
{
    #[Decides(DeskOrderPermission::View)]
    public function allow(): bool
    {
        return true;
    }
}
