<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Panels;

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\Models\Team;
use Filament\Panel;
use Filament\PanelProvider;

/** A Filament panel with tenants at /tenanted; its guard panel `teams` requires tenants. */
final class TenantedFilamentProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel->id('tenanted')->path('tenanted')->authGuard('web');
        $plugin = AzGuardPlugin::make()->guardPanel('teams')
            ->formExtensions(...FilamentFixture::$formExtensions['tenanted'] ?? [])
            ->resources(
                roles: FilamentFixture::$editors['roles'] ?? false,
                roleGrants: FilamentFixture::$editors['role_grants'] ?? false,
                permissionGrants: FilamentFixture::$editors['permission_grants'] ?? false,
                permissions: FilamentFixture::$editors['permissions'] ?? false,
            );

        if (FilamentFixture::$tenantAfterPlugin) {
            return $panel->plugin($plugin)->tenant(Team::class);
        }

        return $panel->tenant(Team::class)->plugin($plugin);
    }
}
