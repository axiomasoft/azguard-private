<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Panels;

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Tests\Fixtures\Filament\Pages\ProbePage;
use Filament\Panel;
use Filament\PanelProvider;

/** A second Filament panel at /backoffice with another guard panel. */
final class BackofficeFilamentProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('backoffice')->path('backoffice')->authGuard('web')
            ->pages([ProbePage::class])
            ->plugin(AzGuardPlugin::make()->guardPanel('backoffice')->manages(['backoffice']));
    }
}
