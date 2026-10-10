<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Ambiguous\Policies\Orders;

final class OrderPolicy
{
    public function allow(): bool
    {
        return true;
    }
}
