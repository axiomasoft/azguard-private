<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use AzGuard\Filament\Authorization\FilamentGate;
use Filament\Widgets\Widget;
use Packman\Attributes\Api;

/**
 * Shows a widget with its permission `widgets.{name}` in the guard panel.
 *
 * @phpstan-require-extends Widget
 */
#[Api(since: '1.0')]
trait AuthorizesWidget
{
    public static function canView(): bool
    {
        return FilamentGate::widget(static::class)->allowed();
    }
}
