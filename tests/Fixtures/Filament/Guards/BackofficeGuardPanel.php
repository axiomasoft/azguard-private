<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Filament\Models\User;

final class BackofficeGuardPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'backoffice';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class, guard: 'web')
            ->roles([BackofficeMemberRole::class])
            ->permissions([EntryPermission::class, PagePermission::class, DatabaseSource::make()]);
    }
}
