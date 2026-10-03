<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Plugins\Support\Policies\Orders;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Guards\Plugins\Support\Permissions\Orders\SupportOrderPermission;

final class SupportOrderPolicy
{
    #[Decides(SupportOrderPermission::View)]
    public function allow(): bool
    {
        return true;
    }
}
