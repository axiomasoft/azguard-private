<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Widgets;

use AzGuard\Filament\Concerns\AuthorizesWidget;
use Filament\Widgets\Widget;

final class KeyedSalesWidget extends Widget
{
    use AuthorizesWidget;

    protected static ?string $azguardKey = 'other-sales';

    protected string $view = 'azguard-fixtures::widget';
}
