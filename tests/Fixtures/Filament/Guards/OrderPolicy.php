<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Policies\Decides;

final class OrderPolicy
{
    #[Decides('orders.view_any')]
    public function viewAny(): bool
    {
        return true;
    }
}
