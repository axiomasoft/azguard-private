<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tests\Fixtures\Guards\Plugins\Desk\DeskAccessPlugin;
use AzGuard\Tests\Fixtures\Guards\Plugins\Support\SupportAccessPlugin;
use AzGuard\Tests\Fixtures\Panels\User;

/**
 * The admin folder plus two plugins that each discover an Orders group of their own.
 */
final class PluginsGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'admin';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class)->plugins([
            SupportAccessPlugin::make(),
            DeskAccessPlugin::make(),
        ]);
    }
}
