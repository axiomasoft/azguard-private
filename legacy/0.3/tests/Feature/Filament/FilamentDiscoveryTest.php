<?php

declare(strict_types=1);

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Pages\DoctorPage;
use AzGuard\Filament\Permissions\FilamentDiscovery;
use AzGuard\Filament\Resources\DirectGrantResource;
use AzGuard\Filament\Resources\RoleResource;
use AzGuard\Tests\Stubs\Filament\GuardedRevenueWidget;
use AzGuard\Tests\Stubs\Filament\GuardedSettingsPage;
use AzGuard\Tests\Stubs\Project;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Resources\Resource;

class FilamentDiscoveryProjectResource extends Resource
{
    protected static ?string $model = Project::class;
}

class FilamentDiscoveryBrokenResource extends Resource
{
    public static function getModel(): string
    {
        throw new RuntimeException('missing model');
    }
}

function registerDiscoveryPanel(Panel $panel): void
{
    // Assigning into the registry avoids Panel::register(), which boots
    // Filament views the test suite does not need for discovery.
    app(PanelRegistry::class)->panels[$panel->getId()] = $panel;
}

it('discovers resources, pages, and widgets from the linked Filament panel', function (): void {
    $plugin = AzGuardPlugin::make()
        ->forPanel('admin')
        ->abilities(['view', 'create']);
    $panel = Panel::make()
        ->id('filament-discovery-'.(string) getmypid())
        ->plugin($plugin)
        ->resources([
            FilamentDiscoveryProjectResource::class,
            FilamentDiscoveryBrokenResource::class,
        ])
        ->pages([GuardedSettingsPage::class])
        ->widgets([GuardedRevenueWidget::class]);
    registerDiscoveryPanel($panel);

    $unlinked = Panel::make()->id('filament-discovery-plain-'.(string) getmypid());
    registerDiscoveryPanel($unlinked);

    $discovery = new FilamentDiscovery(
        abilities: ['view'],
        pageAbility: 'view',
        widgetAbility: 'view',
    );

    $subjects = $discovery->subjects('admin');
    $byName = [];

    foreach ($subjects as $subject) {
        $byName[$subject->name] = $subject;
    }

    expect($byName)->toHaveKeys(['Project', 'GuardedSettingsPage', 'GuardedRevenueWidget'])
        ->and($byName)->not->toHaveKey('FilamentDiscoveryBrokenResource')
        ->and($byName['Project']->model)->toBe(Project::class)
        ->and($byName['Project']->abilities)->toBe(['view', 'create'])
        ->and($byName['GuardedSettingsPage']->abilities)->toBe(['view'])
        ->and($byName['GuardedRevenueWidget']->abilities)->toBe(['view'])
        ->and($discovery->subjects('missing-panel'))->toBe([]);
});

it('skips excluded resources, pages, and widgets', function (): void {
    $plugin = AzGuardPlugin::make()->forPanel('admin');
    $panel = Panel::make()
        ->id('filament-discovery-exclude-'.(string) getmypid())
        ->plugin($plugin)
        ->resources([FilamentDiscoveryProjectResource::class])
        ->pages([GuardedSettingsPage::class])
        ->widgets([GuardedRevenueWidget::class]);
    registerDiscoveryPanel($panel);

    $discovery = new FilamentDiscovery(
        abilities: ['view'],
        exclude: [
            'resources' => [
                FilamentDiscoveryProjectResource::class,
                RoleResource::class,
                DirectGrantResource::class,
            ],
            'pages' => [GuardedSettingsPage::class, DoctorPage::class],
            'widgets' => [GuardedRevenueWidget::class],
        ],
    );

    expect($discovery->subjects('admin'))->toBe([]);
});
