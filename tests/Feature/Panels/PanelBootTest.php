<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Panels\PanelRegistry as PanelRegistryContract;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\AdminReplacementPanel;
use AzGuard\Tests\Fixtures\Panels\BootsPanels;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\PanelModuleProvider;
use AzGuard\Tests\Fixtures\Panels\PanelRegisterTimeReplacer;
use AzGuard\Tests\Fixtures\Panels\PanelReplacingProvider;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\SellerPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Roles\RootRole;
use Illuminate\Support\Facades\Auth;

uses(BootsPanels::class);

beforeEach(function (): void {
    FixturePanel::reset();
});

it('boots without panels: an empty frozen registry', function (): void {
    $registry = app(PanelRegistryContract::class);

    expect($registry)->toBe(app(PanelRegistry::class))
        ->and($registry->isFrozen())->toBeTrue()
        ->and($registry->all())->toBe([])
        ->and(app(AzGuardConfig::class)->panelProviders())->toBe([])
        ->and(app(CurrentPanel::class)->get())->toBeNull();
});

it('compiles the configured panels and freezes the registry once the application has booted', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Back office')->for(User::class, guard: 'web'));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(Seller::class, guard: 'web')->default());

    $this->bootPanels(['providers' => [AdminPanel::class, CabinetPanel::class]], [PanelModuleProvider::class]);

    $registry = app(PanelRegistry::class);

    expect(app()->isBooted())->toBeTrue()
        ->and($registry->isFrozen())->toBeTrue()
        ->and(array_keys($registry->all()))->toBe(['admin', 'cabinet'])
        ->and($registry->get('admin')->label())->toBe('Back office')
        ->and($registry->defaultFor(Seller::class)?->id())->toBe('cabinet')
        ->and($registry->recipe('admin')->roles())->toBe([AnalystRole::class, RootRole::class])
        ->and($registry->recipe('cabinet')->roles())->toBe([RootRole::class])
        ->and(app()->getProviders(AdminPanel::class))->toHaveCount(1)
        ->and(AdminPanel::calls())->toBe(1);
});

it('refuses to change the registry after the application has booted', function (Closure $change): void {
    $this->bootPanels(['providers' => [AdminPanel::class]]);

    expect(fn () => $change(app(PanelRegistryContract::class)))->toThrow(RegistryFrozenException::class);
})->with([
    'register' => [fn (PanelRegistryContract $registry) => $registry->register(CabinetPanel::class)],
    'replace' => [fn (PanelRegistryContract $registry) => $registry->replace(AdminReplacementPanel::class)],
    'configure' => [fn (PanelRegistryContract $registry) => $registry->configure('admin', fn () => null)],
    'configureAll' => [fn (PanelRegistryContract $registry) => $registry->configureAll(fn () => null)],
    'a late service provider' => [fn () => app()->register(SellerPanel::class)],
]);

it('lets a module replace a configured panel while the application boots', function (): void {
    AdminReplacementPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Replaced by the module'));

    $this->bootPanels(['providers' => [AdminPanel::class]], [PanelReplacingProvider::class]);

    expect(app(PanelRegistry::class)->get('admin')->label())->toBe('Replaced by the module')
        ->and(AdminPanel::calls())->toBe(0);
});

it('lets a module replace a configured panel from its register method', function (): void {
    AdminReplacementPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Replaced before the core booted'));

    $this->bootPanels(['providers' => [CabinetPanel::class, AdminPanel::class]], [PanelRegisterTimeReplacer::class]);

    $registry = app(PanelRegistry::class);

    expect($registry->get('admin')->label())->toBe('Replaced before the core booted')
        ->and(array_keys($registry->all()))->toBe(['cabinet', 'admin'])
        ->and(AdminPanel::calls())->toBe(0);
});

it('fails the boot when a module replaces a panel nobody registered', function (): void {
    expect(fn () => $this->bootPanels(['providers' => [CabinetPanel::class]], [PanelRegisterTimeReplacer::class]))
        ->toThrow(UnknownPanelException::class, '"admin"');
});

it('treats the guard of for() as an auth guard and leaves authentication untouched', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class, guard: 'web'));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(Seller::class, guard: 'web'));
    $driver = Auth::getDefaultDriver();

    $this->bootPanels(['providers' => [AdminPanel::class, CabinetPanel::class]]);

    $registry = app(PanelRegistry::class);

    expect(Auth::getDefaultDriver())->toBe($driver)
        ->and(config('auth.guards'))->not->toHaveKeys(['admin', 'cabinet'])
        ->and(array_keys($registry->all()))->toBe(['admin', 'cabinet'])
        ->and($registry->recipe('admin')->subjects())->toBe([['model' => User::class, 'guard' => 'web', 'directory' => null]])
        ->and($registry->recipe('cabinet')->subjects())->toBe([['model' => Seller::class, 'guard' => 'web', 'directory' => null]])
        ->and($registry->find('web'))->toBeNull();
});

it('rejects a configuration the package cannot load', function (array $panels, string $message): void {
    expect(fn () => $this->bootPanels($panels))->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'unknown key in the panels section' => [['providers' => [], 'provider' => [AdminPanel::class]], 'Unknown key in azguard.panels: provider'],
    'providers is not a list of classes' => [['providers' => AdminPanel::class], 'azguard.panels.providers'],
    'a provider entry is not a class name' => [['providers' => [42]], 'azguard.panels.providers'],
    'a listed class is not a panel provider' => [['providers' => [PanelModuleProvider::class]], 'is not a panel provider'],
]);
