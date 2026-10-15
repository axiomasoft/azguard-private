<?php

declare(strict_types=1);

namespace AuditGenerated;

use AzGuard\Filament\Attributes\ForFilament;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Tests\Fixtures\Filament\Widgets\Other\SalesWidget;
use AzGuard\Tests\Fixtures\Filament\Widgets\Stats\SalesWidget;

#[RequiresGrant]
#[ForFilament(SalesWidget::class)]
#[ForFilament(SalesWidget::class)]
enum WidgetPermission: string
{
    case Sales = 'widgets.sales';
    case OtherSales = 'widgets.other-sales';
}
