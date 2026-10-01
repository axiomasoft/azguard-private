<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Filament;

use AzGuard\Filament\Concerns\HasAzGuardWidget;
use Filament\Widgets\Widget;

/**
 * Custom Filament widget opting into AzGuard enforcement via
 * {@see HasAzGuardWidget}. Its catalogued key is
 * `admin.guarded_revenue_widget.view`.
 */
final class GuardedRevenueWidget extends Widget
{
    use HasAzGuardWidget;
}
