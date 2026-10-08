<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Panels;

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use Filament\Panel;
use Filament\PanelProvider;

/** The Filament panel `admin` at /admin; its guard panel is `admin`, it manages `admin` and `seller`. */
final class AdminFilamentProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $plugin = AzGuardPlugin::make()
            ->guardPanel('admin')
            ->manages(['admin', 'seller'])
            ->definitions(FilamentFixture::$definitions)
            ->exclude(
                FilamentFixture::$exclude['resources'] ?? [],
                FilamentFixture::$exclude['pages'] ?? [],
                FilamentFixture::$exclude['widgets'] ?? [],
            );

        if (FilamentFixture::$abilities !== null) {
            $plugin->abilities(FilamentFixture::$abilities);
        }

        if (FilamentFixture::$authority !== null) {
            $plugin->authority(FilamentFixture::$authority);
        }

        return $panel->id('admin')->path('admin')->default()->authGuard('web')
            ->resources(FilamentFixture::$resources)
            ->pages(FilamentFixture::$pages)
            ->widgets(FilamentFixture::$widgets)
            ->plugin($plugin);
    }
}
