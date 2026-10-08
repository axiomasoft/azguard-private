<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\Models\User;

final class AdminGuardPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'admin';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        $panel->for(User::class, guard: 'web')
            ->roles(FilamentFixture::$adminRoles)
            ->permissions([
                EntryPermission::class,
                DatabaseSource::make(),
                // With Resources definitions FilamentSource defines the key of the page.
                ...(FilamentFixture::$definitions === FilamentDefinitions::Enums ? [PagePermission::class] : []),
                ...FilamentFixture::$guardPermissions,
            ]);

        return FilamentFixture::$guardPolicies === [] ? $panel : $panel->policies(FilamentFixture::$guardPolicies);
    }
}
