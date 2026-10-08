<?php

declare(strict_types=1);

namespace App\Guards\Stored;

use App\Guards\Stored\Permissions\StoredPermission;
use App\Models\User;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;

final class StoredGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'stored';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class, guard: 'web')
            ->permissions([StoredPermission::class, DatabaseSource::make()])
            ->cache('file', ttl: 3600);
    }
}
