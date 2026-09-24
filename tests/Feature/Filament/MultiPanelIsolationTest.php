<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Panels\Panel as GuardPanel;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Gate;

class IsolatedProjectResource extends Resource
{
    protected static ?string $model = Project::class;
}

it('keeps catalog keys and resource grants isolated between linked Filament panels', function (): void {
    $adminPlugin = AzGuardPlugin::make()->forPanel('admin');
    $adminPanel = Panel::make()->id('admin-ui')->plugin($adminPlugin);
    $tenantPlugin = AzGuardPlugin::make()
        ->forPanel('tenant')
        ->keyTemplate('{panel}.filament.{resource}.{ability}');
    $tenantPanel = Panel::make()->id('tenant-ui')->plugin($tenantPlugin);
    $adminPanel->resources([IsolatedProjectResource::class]);
    $tenantPanel->resources([IsolatedProjectResource::class]);

    // Filament's static registerPanel() installs a resolving callback before
    // PanelRegistry is created; this test starts after application boot.
    app(PanelRegistry::class)->panels['admin-ui'] = $adminPanel;
    app(PanelRegistry::class)->panels['tenant-ui'] = $tenantPanel;
    AzGuard::registerPanel(GuardPanel::make()->id('tenant'));

    expect($adminPlugin->getPanelId())->toBe('admin')
        ->and($tenantPlugin->getPanelId())->toBe('tenant')
        ->and(config('az-guard-filament.panel'))->toBe('tenant');

    $catalog = app(PermissionCatalog::class);
    expect($catalog->has('admin', 'admin.project.view_any'))->toBeTrue()
        ->and($catalog->has('tenant', 'tenant.filament.project.view_any'))->toBeTrue()
        ->and($catalog->has('tenant', 'admin.project.view_any'))->toBeFalse();

    $adminUser = User::factory()->create();
    $adminRole = Role::create(['name' => 'admin-project-viewer', 'level' => 1]);
    RolePermission::create([
        'role_id' => $adminRole->getKey(),
        'permission_key' => 'admin.project.view_any',
        'panel_id' => 'admin',
    ]);
    $adminUser->assignRole($adminRole);

    $tenantUser = User::factory()->create();
    $tenantRole = Role::create(['name' => 'tenant-project-viewer', 'level' => 1]);
    RolePermission::create([
        'role_id' => $tenantRole->getKey(),
        'permission_key' => 'tenant.filament.project.view_any',
        'panel_id' => 'tenant',
    ]);
    $tenantUser->assignRole($tenantRole);

    Filament::setCurrentPanel($tenantPanel);
    expect(Gate::forUser($adminUser)->allows('viewAny', Project::class))->toBeFalse()
        ->and(Gate::forUser($tenantUser)->allows('viewAny', Project::class))->toBeTrue();

    $tenantPlugin->boot($tenantPanel);
    $this->actingAs($adminUser);
    expect(IsolatedProjectResource::canViewAny())->toBeFalse();

    Filament::setCurrentPanel($adminPanel);
    expect(Gate::forUser($adminUser)->allows('viewAny', Project::class))->toBeTrue()
        ->and(Gate::forUser($tenantUser)->allows('viewAny', Project::class))->toBeFalse();

    $adminPlugin->boot($adminPanel);
    expect(IsolatedProjectResource::canViewAny())->toBeTrue();
});
