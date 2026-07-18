<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelProvider;
use AzGuard\Registry\Builders\EnumPermissionCatalogBuilder;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\Roles\ProjectEditorRole;
use AzGuard\Tests\Stubs\Roles\SuperAdminRole;

final class TestGuardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(id: 'test')
            ->path(path: 'test')
            ->permissionEnums([TestPermission::class])
            ->roleClasses([ManagerRole::class, ProjectEditorRole::class, SuperAdminRole::class]);
    }

    public function register(): void
    {
        AzGuard::registerPanel(function (): Panel {
            $panel = $this->getPanel();
            $panel->basePath(basePath: __DIR__.'/Posts');
            $panel->namespace(namespace: 'AzGuard\Tests\Stubs\Posts');

            return $panel;
        });
    }

    public function boot(): void
    {
        $abstract = 'azguard.catalog_builder.test.enum';
        $this->app->instance($abstract, new EnumPermissionCatalogBuilder(
            panelId: 'test',
            enumClasses: [TestPermission::class],
        ));
        $this->app->tag([$abstract], 'azguard.catalog_builders');
    }
}
