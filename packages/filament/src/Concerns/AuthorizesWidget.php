<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use AzGuard\Filament\Authorization\FilamentGate;
use Filament\Widgets\Widget;

/**
 * Shows a widget with its permission `widgets.{name}` in the guard panel.
 *
 * @phpstan-require-extends Widget
 *
 * @api
 */
trait AuthorizesWidget
{
    public static function canView(): bool
    {
        return FilamentGate::widget(static::class)->allowed();
    }
}
