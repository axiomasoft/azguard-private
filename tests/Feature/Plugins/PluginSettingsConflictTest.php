<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\BootsPanels;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Plugins\CacheTtlPlugin;

uses(BootsPanels::class);

beforeEach(function (): void {
    FixturePanel::reset();
});

it('fails the boot when two plugins set one setting to different values', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
        CacheTtlPlugin::make('acme/audit', ttl: 60),
        CacheTtlPlugin::make('acme/reports', ttl: 600),
    ]));

    try {
        $this->bootPanels(['providers' => [AdminPanel::class]]);
        $this->fail('The application booted with conflicting plugins.');
    } catch (PluginConflictException $e) {
        expect($e->code())->toBe('plugin_conflict')
            ->and($e->getMessage())->toContain('"acme/audit"', '"acme/reports"', '"admin"', '"cache.ttl"');
    }
});

it('boots when the panel provider settles what the plugins disagree on', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->cache(ttl: 900)->plugins([
        CacheTtlPlugin::make('acme/audit', ttl: 60),
        CacheTtlPlugin::make('acme/reports', ttl: 600),
    ]));

    $this->bootPanels(['providers' => [AdminPanel::class]]);
    $settings = app(PanelRegistry::class)->get('admin')->settings();

    expect($settings->cacheTtl())->toBe(900)
        ->and($settings->origin('cache.ttl'))->toBe('provider');
});

it('names the plugin a setting came from', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
        CacheTtlPlugin::make('acme/audit', ttl: 60),
        CacheTtlPlugin::make('acme/reports', ttl: 60),
    ]));

    $this->bootPanels(['providers' => [AdminPanel::class]]);
    $settings = app(PanelRegistry::class)->get('admin')->settings();

    expect($settings->cacheTtl())->toBe(60)
        ->and($settings->origin('cache.ttl'))->toBe('plugin:acme/audit')
        ->and($settings->origin('cache.generation'))->toBe('default');
});

it('puts a plugin above configure for all panels and the configuration', function (bool $withPlugin, int $ttl, string $origin): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins(
        $withPlugin ? [CacheTtlPlugin::make('acme/audit', ttl: 300)] : [],
    ));
    config(['azguard.defaults.cache.ttl' => 100]);

    $registry = new PanelRegistry(app(), new PanelCompiler(static fn (): array => AzGuardConfig::fromRepository(app('config'))->defaults()));
    $registry->configureAll(static fn (PanelBuilder $panel): PanelBuilder => $panel->cache(ttl: 200));
    $registry->register(AdminPanel::class);
    $registry->freeze();
    $settings = $registry->get('admin')->settings();

    expect($settings->cacheTtl())->toBe($ttl)
        ->and($settings->origin('cache.ttl'))->toBe($origin);
})->with([
    'with the plugin' => [true, 300, 'plugin:acme/audit'],
    'without the plugin' => [false, 200, 'configure'],
]);
