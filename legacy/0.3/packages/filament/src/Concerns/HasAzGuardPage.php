<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use AzGuard\Filament\Permissions\PageWidgetAccessEvaluator;

/**
 * Opt-in enforcement for custom Filament Pages.
 *
 * A page's discovered `{panel}.{page}.view` permission is catalogued and
 * grantable in the Role UI, but Filament's own `canAccess()` default
 * (`return true`) means, unless a page overrides it, the permission is
 * cosmetic — hidden from navigation via {@see Page::shouldRegisterNavigation()}
 * at best, still reachable by URL. Add this trait to a custom page to make
 * `canAccess()` consult the same permission the catalog advertises:
 *
 *   use AzGuard\Filament\Concerns\HasAzGuardPage;
 *
 *   class Settings extends Page
 *   {
 *       use HasAzGuardPage;
 *   }
 *
 * Enforced on every mount and every Livewire round-trip (Filament calls
 * `canAccess()` from `mountCanAuthorizeAccess()` and
 * `hydrateCanAuthorizeAccess()`), not just when the nav link is rendered.
 */
trait HasAzGuardPage
{
    public static function canAccess(): bool
    {
        return app(PageWidgetAccessEvaluator::class)->authorize(
            subjectClass: static::class,
            ability: (string) config('az-guard-filament.pages.ability', 'view'),
        );
    }
}
