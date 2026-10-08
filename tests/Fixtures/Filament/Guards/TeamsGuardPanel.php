<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Filament\FilamentTenantResolver;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Filament\Models\Team;
use AzGuard\Tests\Fixtures\Filament\Models\User;

/** A guard panel with required tenants, which the application connects to Filament with the tenant resolver. */
final class TeamsGuardPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'teams';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class, guard: 'web')
            ->permissions([EntryPermission::class, DatabaseSource::make()])
            ->tenants(TenantPolicy::required(Team::class)->requireMembership(TeamMembership::class))
            ->tenantResolvers([FilamentTenantResolver::class]);
    }
}
