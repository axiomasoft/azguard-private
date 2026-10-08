<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Unguarded;

use Filament\Widgets\Widget;

final class PlainWidget extends Widget
{
    protected string $view = 'azguard-fixtures::widget';
}
