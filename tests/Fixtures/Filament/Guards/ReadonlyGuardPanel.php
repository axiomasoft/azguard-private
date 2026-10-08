<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tests\Fixtures\Filament\Models\User;

/** A panel without a source that stores grants: nothing in it can be edited. */
final class ReadonlyGuardPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'readonly';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class, guard: 'web')->permissions([EntryPermission::class]);
    }
}
