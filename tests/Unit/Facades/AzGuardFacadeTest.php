<?php

declare(strict_types=1);

use AzGuard\AzGuardManager;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Modules\AdjustsPanels;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\BootsPanels;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use Illuminate\Contracts\Foundation\Application;

uses(BootsPanels::class);

beforeEach(function (): void {
    FixturePanel::reset();
});

it('binds one stateless manager as azguard and resolves the facade to it', function (): void {
    $manager = app(AzGuardManager::class);

    expect($manager)->toBe(app('azguard'))
        ->and(AzGuard::getFacadeRoot())->toBe($manager)
        ->and(array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(AzGuardManager::class))->getProperties(),
        ))->toBe(['app'])
        ->and((new ReflectionProperty(AzGuardManager::class, 'app'))->getType()?->getName())->toBe(Application::class);
});

it('declares only the five panel methods', function (): void {
    $doc = (new ReflectionClass(AzGuard::class))->getDocComment();

    expect($doc)->toBeString()
        ->and(preg_match_all('/@method static /', (string) $doc))->toBe(5)
        ->and($doc)->toContain('registerPanel(')
        ->and($doc)->toContain('configurePanel(')
        ->and($doc)->toContain('configurePanels(')
        ->and($doc)->toContain('panels()')
        ->and($doc)->toContain('currentPanel()')
        ->and($doc)->not->toContain('sources()')
        ->and($doc)->not->toContain('fake()')
        ->and($doc)->not->toContain('authorize(')
        ->and($doc)->not->toContain('withinScope(')
        ->and($doc)->not->toContain('actingAs(');
});

it('reads the panels and the current panel without choosing one', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Back office'));
    $this->bootPanels(['providers' => [AdminPanel::class]]);

    $registry = app(PanelRegistry::class);

    expect(AzGuard::panels())->toBe($registry->all())
        ->and(AzGuard::currentPanel())->toBeNull();

    app(CurrentPanel::class)->set($registry->get('admin'));

    expect(AzGuard::currentPanel())->toBe($registry->get('admin'))
        ->and(AzGuard::currentPanel()?->id())->toBe('admin');
});

it('records configurePanel as the provider and configurePanels as configure', function (): void {
    $this->bootPanels(['providers' => [AdminPanel::class]], [AdjustsPanels::class]);

    $recipe = app(PanelRegistry::class)->recipe('admin');

    expect($recipe->layered(PanelRecipe::LABEL)[0]['origin']['kind'])->toBe(PanelRecipe::PROVIDER)
        ->and($recipe->layered(PanelRecipe::LABEL)[0]['value'])->toBe('Module label')
        ->and($recipe->layered(PanelRecipe::DESCRIPTION)[0]['origin']['kind'])->toBe(PanelRecipe::CONFIGURE)
        ->and(app(PanelRegistry::class)->get('admin')->label())->toBe('Module label');
});

it('refuses facade calls once the registry is frozen', function (Closure $call): void {
    $this->bootPanels(['providers' => [AdminPanel::class]]);

    expect($call)->toThrow(RegistryFrozenException::class, 'frozen');
})->with([
    'registerPanel' => [fn () => AzGuard::registerPanel(CabinetPanel::class)],
    'configurePanel' => [fn () => AzGuard::configurePanel('admin', static fn () => null)],
    'configurePanels' => [fn () => AzGuard::configurePanels(static fn () => null)],
]);
