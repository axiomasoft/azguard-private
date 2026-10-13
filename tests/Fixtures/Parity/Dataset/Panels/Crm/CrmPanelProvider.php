<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity\Dataset\Panels\Crm;

use AzGuard\Audit\AuditPlugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Changes\Roles\RootRole;
use AzGuard\Tests\Fixtures\Changes\Roles\SupportRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\CrmGuardPanelProvider;
use AzGuard\Tests\Fixtures\Parity\Dataset\Dataset;

/** Panel `crm` of the baseline dataset: the CRM stand with tenants and project scopes, journaled by the Audit plugin. */
final class CrmPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'crm';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        [$sources, $configure] = [CrmWorld::$sources, CrmWorld::$configure];

        try {
            CrmWorld::$sources = [Dataset::source()];
            CrmWorld::$configure = null;
            (new CrmGuardPanelProvider($this->app))->panel($panel);
        } finally {
            [CrmWorld::$sources, CrmWorld::$configure] = [$sources, $configure];
        }

        return $panel->discover(Dataset::crmGuards(), 'AzGuard\\Tests\\Fixtures\\Crm\\Guards\\Crm')
            ->roles([RootRole::class, AuditorRole::class, SupportRole::class])
            ->plugins([AuditPlugin::make()]);
    }
}
