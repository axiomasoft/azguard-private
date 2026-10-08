<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\Models\User;

/** A managed panel without tenants that keeps dynamic permissions. */
final class SellerGuardPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'seller';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        $panel->for(User::class, guard: 'web')
            ->roles([SellerMemberRole::class])
            ->permissions([EntryPermission::class, DatabaseSource::make()->dynamicPermissions(), ...FilamentFixture::$sellerPermissions]);

        return FilamentFixture::$sellerPolicies === [] ? $panel : $panel->policies(FilamentFixture::$sellerPolicies);
    }
}
