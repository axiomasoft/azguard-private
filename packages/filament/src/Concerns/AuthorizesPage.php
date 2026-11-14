<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use AzGuard\Attributes\Api;
use AzGuard\Filament\Authorization\FilamentGate;
use Filament\Pages\Page;

/**
 * Opens a page with its permission `pages.{slug}` in the guard panel.
 *
 * @phpstan-require-extends Page
 */
#[Api(since: '1.0')]
trait AuthorizesPage
{
    public static function canAccess(): bool
    {
        return FilamentGate::page(static::class)->allowed();
    }

    /**
     * Livewire runs boot before the page mount and before Filament's canAccess hook, so a refused page must not
     * execute its own mount (doctor, settings, schema) and only then answer 403.
     */
    public function bootAuthorizesPage(): void
    {
        abort_unless(static::canAccess(), 403);
    }
}
