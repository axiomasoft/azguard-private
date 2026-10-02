<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelSettings;
use AzGuard\Panels\Reads;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\BootsPanels;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\PanelModuleProvider;
use AzGuard\Tests\Fixtures\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Roles\RootRole;
use AzGuard\Tests\Fixtures\Roles\SellerRole;

uses(BootsPanels::class);

beforeEach(function (): void {
    FixturePanel::reset();
});

/**
 * Compiles one panel against the configuration of the running application, the way the core provider does.
 *
 * @param  list<'default'|'configure'|'plugin'|'provider'>  $layers  layers that set `cache.ttl`
 */
function settingsOfLayers(array $layers): PanelSettings
{
    $ttl = ['default' => 100, 'configure' => 200, 'plugin' => 300, 'provider' => 400];
    config(['azguard.defaults.cache.ttl' => in_array('default', $layers, true) ? $ttl['default'] : 3600]);

    $recipe = new PanelRecipe('admin');
    $builder = new PanelBuilder($recipe);

    if (in_array('configure', $layers, true)) {
        $recipe->during(PanelRecipe::configure(), fn () => $builder->cache(ttl: $ttl['configure']));
    }

    if (in_array('plugin', $layers, true)) {
        $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $builder->cache(ttl: $ttl['plugin']));
    }

    if (in_array('provider', $layers, true)) {
        $builder->cache(ttl: $ttl['provider']);
    }

    $recipe->seal();
    $compiler = new PanelCompiler(static fn (): array => AzGuardConfig::fromRepository(app('config'))->defaults());

    return $compiler->settings($recipe);
}

it('takes a setting from the highest layer that sets it', function (array $layers, int $ttl, string $origin): void {
    $settings = settingsOfLayers($layers);

    expect($settings->cacheTtl())->toBe($ttl)
        ->and($settings->origin('cache.ttl'))->toBe($origin);
})->with([
    'only the configuration' => [['default'], 100, 'default'],
    'configure for all panels over the configuration' => [['default', 'configure'], 200, 'configure'],
    'a plugin over configure' => [['default', 'configure', 'plugin'], 300, 'plugin:acme/audit'],
    'the provider over a plugin' => [['default', 'plugin', 'provider'], 400, 'provider'],
    'all four at once' => [['default', 'configure', 'plugin', 'provider'], 400, 'provider'],
    'nothing but the package default' => [[], 3600, 'default'],
]);

it('reports a conflict of two plugins unless the provider sets the value', function (): void {
    $compile = static function (bool $providerDecides): PanelSettings {
        $recipe = new PanelRecipe('admin');
        $builder = new PanelBuilder($recipe);
        $recipe->during(PanelRecipe::plugin('acme/audit', 1), fn () => $builder->cache(ttl: 60));
        $recipe->during(PanelRecipe::plugin('acme/reports', 2), fn () => $builder->cache(ttl: 600));

        if ($providerDecides) {
            $builder->cache(ttl: 900);
        }

        return (new PanelCompiler(static fn (): array => app(AzGuardConfig::class)->defaults()))->settings($recipe);
    };

    try {
        $compile(false);
        $this->fail('The settings compiled with conflicting plugins.');
    } catch (PluginConflictException $e) {
        expect($e->code())->toBe('plugin_conflict');
    }

    expect($compile(true)->cacheTtl())->toBe(900);
});

it('compiles booted panels with the defaults of the configuration and reports their origin', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->cache(store: 'array', ttl: 90)->consistency(Reads::Default));

    $this->bootPanels(['providers' => [AdminPanel::class, CabinetPanel::class]]);

    $admin = app(PanelRegistry::class)->get('admin')->settings();
    $cabinet = app(PanelRegistry::class)->get('cabinet')->settings();

    expect($admin->cacheStore())->toBe('array')
        ->and($admin->origin('cache.ttl'))->toBe('provider')
        ->and($admin->reads())->toBe(Reads::Default)
        ->and($admin->origin('cache.generation'))->toBe('default')
        ->and($cabinet->toArray())->toBe([
            'resource_prefix' => ['value' => 'cabinet', 'origin' => 'default'],
            'gate.mode' => ['value' => 'authoritative', 'origin' => 'default'],
            'cache.store' => ['value' => null, 'origin' => 'default'],
            'cache.ttl' => ['value' => 3600, 'origin' => 'default'],
            'cache.generation' => ['value' => 1, 'origin' => 'default'],
            'consistency.reads' => ['value' => 'primary', 'origin' => 'default'],
            'consistency.state_refresh' => ['value' => 'request', 'origin' => 'default'],
            'trace_decisions' => ['value' => false, 'origin' => 'default'],
        ]);
});

it('keeps list items of the provider and of configure for all panels in layer order after a boot', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->roles([SellerRole::class]));

    $this->bootPanels(['providers' => [AdminPanel::class, CabinetPanel::class]], [PanelModuleProvider::class]);

    $recipe = app(PanelRegistry::class)->recipe('admin');

    expect($recipe->roles())->toBe([SellerRole::class, AnalystRole::class, RootRole::class])
        ->and(array_map(static fn (array $record): string => $record['origin']['kind'], $recipe->layered(PanelRecipe::ROLES)))
        ->toBe(['provider', 'provider', 'configure']);
});

it('rejects defaults that would turn a guarantee off or leave an enum', function (array $defaults, string $code, string $message): void {
    config(['azguard.defaults' => $defaults]);

    try {
        $config = AzGuardConfig::fromRepository(app('config'));
        (new PanelCompiler(static fn (): array => $config->defaults()))->settings(new PanelRecipe('admin'));
        $this->fail('The defaults were accepted.');
    } catch (InvalidConfigurationException $e) {
        expect($e->code())->toBe($code)
            ->and($e->getMessage())->toContain($message);
    }
})->with([
    'a gate mode other than authoritative' => [['gate' => ['mode' => 'permissive']], 'invalid_configuration.enum', 'gate.mode'],
    'a key for direct writes' => [['strict_writes' => false], 'invalid_configuration', 'Unknown key in azguard.defaults: strict_writes'],
    'a persistent store without a ttl' => [['cache' => ['store' => 'redis', 'ttl' => null]], 'invalid_configuration.cache_ttl', 'cache.ttl'],
]);

it('fails the boot of every panel when the configuration leaves an enum', function (): void {
    $this->bootPanels(['providers' => [AdminPanel::class]]);
    config(['azguard.defaults.consistency.reads' => 'replica']);

    $registry = new PanelRegistry(app(), new PanelCompiler(static fn (): array => AzGuardConfig::fromRepository(app('config'))->defaults()));
    $registry->register(AdminPanel::class);

    expect(fn () => $registry->freeze())->toThrow(InvalidConfigurationException::class, '"consistency.reads" is "replica"')
        ->and($registry->isFrozen())->toBeFalse();
});

it('turns the prefix off for every panel from the configuration and lets a panel name its own', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix('backoffice'));
    config(['azguard.defaults.resource_prefix' => false]);

    $registry = new PanelRegistry(app(), new PanelCompiler(static fn (): array => AzGuardConfig::fromRepository(app('config'))->defaults()));
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->freeze();

    expect($registry->get('cabinet')->prefix())->toBeNull()
        ->and($registry->get('admin')->prefix())->toBe('backoffice')
        ->and($registry->forPrefix('backoffice')?->id())->toBe('admin')
        ->and($registry->forPrefix('cabinet'))->toBeNull();
});
