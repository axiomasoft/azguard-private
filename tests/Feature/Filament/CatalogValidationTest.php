<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Pages\DoctorPage;
use AzGuard\Filament\Permissions\FilamentActions;
use AzGuard\Filament\Permissions\FilamentDiscovery;
use AzGuard\Filament\Permissions\FilamentPermissionCatalogBuilder;
use AzGuard\Filament\Permissions\PermissionDiscovery;
use AzGuard\Filament\Permissions\PermissionSchema;
use AzGuard\Filament\Resources\DirectGrantResource;
use AzGuard\Filament\Resources\RoleResource;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Registry\Builders\CompositePermissionCatalog;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Definitions\SimplePermissionDefinition;
use AzGuard\Tests\Stubs\Filament\BypassCatalogProjectResource;
use AzGuard\Tests\Stubs\Filament\CatalogProjectResource;
use AzGuard\Tests\Stubs\Filament\GuardedRevenueWidget;
use AzGuard\Tests\Stubs\Filament\GuardedSettingsPage;
use AzGuard\Tests\Stubs\Filament\OverriddenRevenueWidget;
use AzGuard\Tests\Stubs\Filament\OverriddenSettingsPage;
use AzGuard\Tests\Stubs\Filament\PlainSettingsPage;
use AzGuard\Tests\Stubs\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Artisan;

afterEach(function (): void {
    CatalogProjectResource::checkPolicyExistence();
    CatalogProjectResource::skipAuthorization(false);
});

function catalogValidationPanel(
    array $pages = [GuardedSettingsPage::class],
    array $widgets = [GuardedRevenueWidget::class],
    array $resources = [CatalogProjectResource::class],
): Panel {
    config([
        'az-guard-filament.exclude.resources' => [RoleResource::class, DirectGrantResource::class],
        'az-guard-filament.exclude.pages' => [DoctorPage::class],
    ]);

    AzGuard::panel('admin')
        ->basePath(dirname(__DIR__, 2).'/Stubs/Filament')
        ->namespace('AzGuard\Tests\Stubs\Filament');

    $panel = Panel::make()->id('catalog-validation')
        ->plugin(AzGuardPlugin::make()->forPanel('admin'))
        ->resources($resources)
        ->pages($pages)
        ->widgets($widgets);

    Filament::getFacadeRoot()->registerPanel($panel);
    Filament::setCurrentPanel($panel);
    $panel->getPlugin('az-guard')->boot($panel);

    $discovery = new FilamentDiscovery(
        abilities: (array) config('az-guard-filament.abilities'),
        pageAbility: (string) config('az-guard-filament.pages.ability', 'view'),
        widgetAbility: (string) config('az-guard-filament.widgets.ability', 'view'),
        exclude: (array) config('az-guard-filament.exclude'),
    );
    app()->instance(PermissionDiscovery::class, $discovery);
    app()->instance(FilamentDiscovery::class, $discovery);
    app()->instance(PermissionSchema::class, PermissionSchema::fromConfig((array) config('az-guard-filament')));
    app()->forgetInstance(FilamentPermissionCatalogBuilder::class);
    app()->instance(PermissionCatalog::class, new CompositePermissionCatalog(
        [app(FilamentPermissionCatalogBuilder::class)],
        ['admin'],
    ));

    return $panel;
}

function validateFilamentCatalog(bool $strict = true): array
{
    $exit = Artisan::call('guard:catalog:validate', [
        '--panel' => 'admin', '--json' => true, '--strict' => $strict,
    ]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

it('strictly validates actual resource page and widget runtime bindings', function (): void {
    catalogValidationPanel();
    $catalog = app(PermissionCatalog::class);
    $keys = array_map(fn ($definition) => $definition->key(), $catalog->all('admin'));

    expect($keys)->toBe([
        'admin.project.view_any', 'admin.project.view', 'admin.project.create',
        'admin.guarded_settings_page.view', 'admin.guarded_revenue_widget.view',
    ]);
    [$exit, $payload] = validateFilamentCatalog();
    expect($exit)->toBe(0)->and($payload['warnings'])->toBe([])->and($payload['errors'])->toBe([]);
    expect(array_map(fn ($definition) => $definition->key(), $catalog->all('admin')))->toBe($keys);

    // Exercise the native Filament entry points, not only the discovery metadata.
    $user = User::factory()->create();
    $this->actingAs($user);
    expect(CatalogProjectResource::canViewAny())->toBeFalse()
        ->and(GuardedSettingsPage::canAccess())->toBeFalse()
        ->and(GuardedRevenueWidget::canView())->toBeFalse();
    $role = Role::create(['name' => 'catalog-runtime', 'level' => 1]);
    foreach (['admin.project.view_any', 'admin.guarded_settings_page.view', 'admin.guarded_revenue_widget.view'] as $key) {
        RolePermission::create(['role_id' => $role->getKey(), 'panel_id' => 'admin', 'permission_key' => $key]);
    }
    $user->assignRole($role->name);
    $user->load('roles');
    expect(CatalogProjectResource::canViewAny())->toBeTrue()
        ->and(GuardedSettingsPage::canAccess())->toBeTrue()
        ->and(GuardedRevenueWidget::canView())->toBeTrue();
});

it('keeps warnings for disabled or policy-source enforcement', function (string $setting, mixed $value): void {
    catalogValidationPanel();
    config([$setting => $value]);
    [$exit, $payload] = validateFilamentCatalog();
    expect($exit)->toBe(1)->and($payload['warnings'])->toHaveCount(5);
    [$exit] = validateFilamentCatalog(strict: false);
    expect($exit)->toBe(0);
})->with([
    'disabled' => ['az-guard-filament.enforce', false],
    'policy source' => ['az-guard-filament.source', 'policy'],
]);

it('does not treat trait names or unguarded discovery as active hooks', function (): void {
    catalogValidationPanel(
        pages: [PlainSettingsPage::class, OverriddenSettingsPage::class],
        widgets: [OverriddenRevenueWidget::class],
    );
    [$exit, $payload] = validateFilamentCatalog();
    expect($exit)->toBe(1)->and($payload['warnings'])->toHaveCount(3);
    expect(implode("\n", $payload['warnings']))
        ->toContain('admin.plain_settings_page.view', 'admin.overridden_settings_page.view', 'admin.overridden_revenue_widget.view')
        ->not->toContain('admin.project.view_any');
});

it('keeps orphan forged and unsupported resource ability warnings', function (): void {
    config(['az-guard-filament.abilities' => ['view_any', 'invented']]);
    catalogValidationPanel();
    $definitions = app(PermissionCatalog::class)->all('admin');
    $definitions[] = new SimplePermissionDefinition('admin.forged.view', 'admin', dynamic: true);
    $definitions[] = new SimplePermissionDefinition('admin.project.export', 'admin');
    $definitions[] = new SimplePermissionDefinition('admin.guarded_settings_page.create', 'admin');
    $definitions[] = new SimplePermissionDefinition('admin.absent_widget.view', 'admin');
    $catalog = Mockery::mock(PermissionCatalog::class);
    $catalog->shouldReceive('all')->with('admin')->andReturn($definitions);
    app()->instance(PermissionCatalog::class, $catalog);

    [$exit, $payload] = validateFilamentCatalog();
    expect($exit)->toBe(1)->and($payload['warnings'])->toHaveCount(5);
    expect(implode("\n", $payload['warnings']))
        ->toContain('admin.forged.view', 'admin.project.export', 'admin.project.invented', 'admin.guarded_settings_page.create', 'admin.absent_widget.view')
        ->not->toContain('admin.project.view_any');
});

it('does not accept previously catalogued keys after the linked panel disappears', function (): void {
    catalogValidationPanel();
    app(PermissionCatalog::class)->all('admin');
    Filament::shouldReceive('getPanels')->andReturn([]);
    [$exit, $payload] = validateFilamentCatalog();
    expect($exit)->toBe(1)->and($payload['warnings'])->toHaveCount(5);
});

it('does not accept a resource that skips native authorization', function (): void {
    catalogValidationPanel();
    CatalogProjectResource::skipAuthorization();

    try {
        [$exit, $payload] = validateFilamentCatalog();
        expect($exit)->toBe(1)->and($payload['warnings'])->toHaveCount(3);
    } finally {
        CatalogProjectResource::skipAuthorization(false);
    }
});

it('keeps the diagnostic for an overridden resource authorization hook', function (): void {
    catalogValidationPanel(resources: [BypassCatalogProjectResource::class]);
    [$exit, $payload] = validateFilamentCatalog();
    expect($exit)->toBe(1)->and($payload['warnings'])->toHaveCount(1)
        ->and($payload['warnings'][0])->toContain('admin.project.view_any');
});

it('does not accept excluded subjects retained in a stale catalog', function (): void {
    catalogValidationPanel();
    app(PermissionCatalog::class)->all('admin');
    app()->instance(FilamentDiscovery::class, new FilamentDiscovery(
        abilities: ['view_any', 'view', 'create'],
        exclude: [
            'resources' => [CatalogProjectResource::class, RoleResource::class, DirectGrantResource::class],
            'pages' => [GuardedSettingsPage::class, DoctorPage::class],
            'widgets' => [GuardedRevenueWidget::class],
        ],
    ));
    [$exit, $payload] = validateFilamentCatalog();
    expect($exit)->toBe(1)->and($payload['warnings'])->toHaveCount(5);
});

it('does not claim resource keys when the runtime discovery has no model binding', function (): void {
    catalogValidationPanel();
    app(PermissionCatalog::class)->all('admin');
    app()->instance(PermissionDiscovery::class, new class implements PermissionDiscovery
    {
        public function subjects(string $panelId): array
        {
            return [];
        }
    });
    [$exit, $payload] = validateFilamentCatalog();
    expect($exit)->toBe(1)->and($payload['warnings'])->toHaveCount(3);
});

it('uses the configured key schema and page widget abilities for enum source too', function (): void {
    config([
        'az-guard-filament.key' => '{panel}.{ability}.{resource}',
        'az-guard-filament.case' => 'kebab',
        'az-guard-filament.pages.ability' => 'access',
        'az-guard-filament.widgets.ability' => 'display',
        'az-guard-filament.source' => 'enum',
    ]);
    catalogValidationPanel();
    [$exit, $payload] = validateFilamentCatalog();
    expect($exit)->toBe(0)->and($payload['warnings'])->toBe([]);
    expect(app(PermissionCatalog::class)->has('admin', 'admin.access.guarded-settings-page'))->toBeTrue()
        ->and(app(PermissionCatalog::class)->has('admin', 'admin.display.guarded-revenue-widget'))->toBeTrue();
});

it('recognizes every supported native resource ability', function (): void {
    config(['az-guard-filament.abilities' => array_values(array_unique(FilamentActions::MAP))]);
    catalogValidationPanel();
    [$exit, $payload] = validateFilamentCatalog();
    expect($exit)->toBe(0)->and($payload['warnings'])->toBe([]);
});
