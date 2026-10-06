<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Backoffice;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\CrmGuardPanelProvider;

final class BackofficePanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'backoffice';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        $sources = CrmWorld::$sources;
        $configure = CrmWorld::$configure;

        try {
            CrmWorld::$sources = [CrmWorld::database()];
            CrmWorld::$configure = CrmWorld::$backofficeConfigure;
            (new CrmGuardPanelProvider($this->app))->panel($panel);
        } finally {
            CrmWorld::$sources = $sources;
            CrmWorld::$configure = $configure;
        }

        return $panel->discover(dirname(__DIR__).'/Crm', 'AzGuard\\Tests\\Fixtures\\Crm\\Guards\\Crm');
    }
}
