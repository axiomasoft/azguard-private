<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament;

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use Filament\Panel;
use Filament\PanelProvider;

/** The Filament panel of the CRM at /crm/{organization}, over the guard panel `crm`. */
final class CrmFilamentProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('crm')->path('crm')->default()->authGuard('web')
            ->tenant(Organization::class)
            ->resources([ClientResource::class])
            ->widgets([ClientCountWidget::class])
            ->plugin(AzGuardPlugin::make()->guardPanel('crm')->manages(['crm']));
    }
}
