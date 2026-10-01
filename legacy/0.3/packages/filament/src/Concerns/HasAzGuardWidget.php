<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use AzGuard\Filament\Permissions\PageWidgetAccessEvaluator;

/**
 * Opt-in enforcement for custom Filament Widgets — the {@see HasAzGuardPage}
 * counterpart for `Widget::canView()`.
 *
 * Filament widgets never go through the Gate; `canView()` defaults to
 * `return true`, so an un-enforced widget only *hides itself* from the
 * dashboard while its markup — and any data it queries — remains reachable
 * by anyone who can render the page it's placed on. Add this trait to a
 * custom widget to make `canView()` consult its catalogued
 * `{panel}.{widget}.view` permission:
 *
 *   use AzGuard\Filament\Concerns\HasAzGuardWidget;
 *
 *   class RevenueChart extends Widget
 *   {
 *       use HasAzGuardWidget;
 *   }
 */
trait HasAzGuardWidget
{
    public static function canView(): bool
    {
        return app(PageWidgetAccessEvaluator::class)->authorize(
            subjectClass: static::class,
            ability: (string) config('az-guard-filament.widgets.ability', 'view'),
        );
    }
}
