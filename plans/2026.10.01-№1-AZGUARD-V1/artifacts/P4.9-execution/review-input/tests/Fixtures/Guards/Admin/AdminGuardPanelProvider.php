<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tests\Fixtures\Panels\User;

/**
 * Panel folder used by discovery: permissions, policies, roles and scopes live beside this provider.
 */
final class AdminGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'admin';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class);
    }
}
