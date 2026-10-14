<?php

declare(strict_types=1);

namespace App\Guards\Audited;

use App\Guards\Audited\Permissions\ReportPermission;
use App\Guards\Audited\Roles\ReviewerRole;
use App\Models\User;
use AzGuard\Audit\AuditPlugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;
use Illuminate\Database\Eloquent\Relations\Relation;

/** One panel with stored grants whose changes the Audit plugin journals: the slice the relocation rehearsal moves. */
final class AuditedGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'audited';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class, guard: 'web')
            ->permissions([ReportPermission::class, DatabaseSource::make()])
            ->roles([ReviewerRole::class])
            ->plugins([AuditPlugin::make()]);
    }

    public function boot(): void
    {
        Relation::morphMap(['user' => User::class]);
    }
}
