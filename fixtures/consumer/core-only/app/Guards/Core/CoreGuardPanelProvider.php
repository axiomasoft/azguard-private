<?php

declare(strict_types=1);

namespace App\Guards\Core;

use App\Guards\Core\Permissions\NotePermission;
use App\Guards\Core\Roles\EditorRole;
use App\Models\User;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;
use Illuminate\Database\Eloquent\Relations\Relation;

/** One panel with stored grants and no plugin: core is all it needs. */
final class CoreGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'core';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class, guard: 'web')
            ->permissions([NotePermission::class, DatabaseSource::make()])
            ->roles([EditorRole::class]);
    }

    public function boot(): void
    {
        Relation::morphMap(['user' => User::class]);
    }
}
