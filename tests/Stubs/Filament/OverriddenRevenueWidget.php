<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

use AzGuard\Filament\Concerns\HasAzGuardWidget;
use Filament\Widgets\Widget;

final class OverriddenRevenueWidget extends Widget
{
    use HasAzGuardWidget;

    public static function canView(): bool
    {
        return true;
    }
}
