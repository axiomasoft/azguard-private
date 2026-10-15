<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Widgets\OfResource;

use Filament\Widgets\Widget;

/** A widget of a resource that no AzGuard trait decides. */
final class UnprotectedWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'azguard-fixtures::widget';
}
