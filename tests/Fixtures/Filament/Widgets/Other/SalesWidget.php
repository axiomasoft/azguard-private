<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Widgets\Other;

use AzGuard\Filament\Concerns\AuthorizesWidget;
use Filament\Widgets\Widget;

final class SalesWidget extends Widget
{
    use AuthorizesWidget;

    protected string $view = 'azguard-fixtures::widget';
}
