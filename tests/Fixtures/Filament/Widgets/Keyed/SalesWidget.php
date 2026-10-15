<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Widgets\Keyed;

use AzGuard\Filament\Concerns\AuthorizesWidget;
use Filament\Widgets\Widget;

/** The basename of `Stats\SalesWidget`, with a key of its own. */
final class SalesWidget extends Widget
{
    use AuthorizesWidget;

    protected static ?string $azguardKey = 'keyed-sales';

    protected string $view = 'azguard-fixtures::widget';
}
