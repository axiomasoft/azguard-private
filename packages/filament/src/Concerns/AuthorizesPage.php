<?php

declare(strict_types=1);

namespace AzGuard\Filament\Concerns;

use AzGuard\Filament\Authorization\FilamentGate;
use Filament\Pages\Page;

/**
 * Opens a page with its permission `pages.{slug}` in the guard panel.
 *
 * @phpstan-require-extends Page
 *
 * @api
 */
trait AuthorizesPage
{
    public static function canAccess(): bool
    {
        return FilamentGate::page(static::class)->allowed();
    }
}
