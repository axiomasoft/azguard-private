<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

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
            ->roles([AdminMemberRole::class])
            ->permissions([EntryPermission::class, DatabaseSource::make(), ...FilamentFixture::$guardPermissions]);

        return FilamentFixture::$guardPolicies === [] ? $panel : $panel->policies(FilamentFixture::$guardPolicies);
    }
}
