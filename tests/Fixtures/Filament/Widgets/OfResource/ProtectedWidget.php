<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Widgets\OfResource;

use AzGuard\Filament\Concerns\AuthorizesWidget;
use Filament\Widgets\Widget;

/** A widget of a resource that AzGuard decides. */
final class ProtectedWidget extends Widget
{
    use AuthorizesWidget;

    protected static bool $isLazy = false;

    protected string $view = 'azguard-fixtures::widget';
}
