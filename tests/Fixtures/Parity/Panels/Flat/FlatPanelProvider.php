<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity\Panels\Flat;

use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\CrmGuardPanelProvider;
use AzGuard\Tests\Fixtures\Parity\ParityWorld;

/**
 * Panel `flat` of the parity world: the CRM stand on the same storage as `crm` and `backoffice`, without a scope
 * declaration (mode `AssignmentScopeMode::None`). Its own folder, so discovery finds only the CRM definitions.
 */
final class FlatPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'flat';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        [$sources, $configure] = [CrmWorld::$sources, CrmWorld::$configure];

        try {
            CrmWorld::$sources = [CrmWorld::database()];
            CrmWorld::$configure = null;
            (new CrmGuardPanelProvider($this->app))->panel($panel);
        } finally {
            [CrmWorld::$sources, CrmWorld::$configure] = [$sources, $configure];
        }

        return ParityWorld::common($panel->discover(ParityWorld::crmGuards(), 'AzGuard\\Tests\\Fixtures\\Crm\\Guards\\Crm'))
            ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::None, []));
    }
}
