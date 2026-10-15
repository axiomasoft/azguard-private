<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Widgets\OfResource;

use Filament\Widgets\Widget;

/** A widget that no panel, resource or configuration lists: a page returns it at run time. */
final class DynamicWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'azguard-fixtures::widget';
}
