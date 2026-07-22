<?php

declare(strict_types=1);

use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Resources\DirectGrantResource;
use AzGuard\Filament\Resources\RoleResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

it('implements Filament Plugin contract', function () {
    expect(AzGuardPlugin::make())->toBeInstanceOf(Plugin::class);
});

it('returns correct plugin id', function () {
    expect(AzGuardPlugin::make()->getId())->toBe('az-guard');
});

it('make() returns new instance each time', function () {
    $a = AzGuardPlugin::make();
    $b = AzGuardPlugin::make();

    expect($a)->not->toBe($b);
});

it('defaults panelId to config value (admin)', function () {
    expect(AzGuardPlugin::make()->getPanelId())->toBe('admin');
});

it('reads default panelId from the az-guard-filament.panel config key', function () {
    // Canary: a non-default sentinel proves getPanelId() genuinely resolves
    // the config value rather than falling back to the hardcoded 'admin'
    // (which would mask a config that never loaded — the vendor-shadow trap).
    config(['az-guard-filament.panel' => 'canary-panel']);

    expect(AzGuardPlugin::make()->getPanelId())->toBe('canary-panel');
});

it('forPanel() overrides the config default panel', function () {
    // Explicit forPanel() must win over the config default, regardless of
    // what the config key holds.
    config(['az-guard-filament.panel' => 'config-panel']);

    expect(AzGuardPlugin::make()->forPanel('explicit-panel')->getPanelId())
        ->toBe('explicit-panel');
});

it('forPanel() sets panelId and returns same instance', function () {
    $plugin = AzGuardPlugin::make();
    $result = $plugin->forPanel('admin');

    expect($result)->toBe($plugin)
        ->and($plugin->getPanelId())->toBe('admin');
});

it('forPanel() accepts arbitrary panel ids', function (string $panelId) {
    expect(AzGuardPlugin::make()->forPanel($panelId)->getPanelId())->toBe($panelId);
})->with(['admin', 'tenant', 'super-admin', 'app']);

it('register() injects RoleResource and DirectGrantResource into panel', function () {
    $registered = [];

    $panel = Mockery::mock(Panel::class);
    $panel->shouldReceive('resources')
        ->once()
        ->withArgs(function (array $resources) use (&$registered) {
            $registered = $resources;

            return true;
        })
        ->andReturnSelf();
    $panel->shouldReceive('pages')->once()->andReturnSelf();

    AzGuardPlugin::make()->register($panel);

    expect($registered)->toContain(RoleResource::class)
        ->toContain(DirectGrantResource::class);
});

it('boot() runs without exception', function () {
    $panel = Mockery::mock(Panel::class);

    expect(fn () => AzGuardPlugin::make()->boot($panel))->not->toThrow(Throwable::class);
});

it('make() resolves the container-bound instance — enabling test/app swaps', function () {
    // AzGuardPlugin is final, so a "swap" isn't subclassing — it's replacing
    // what the container hands back for AzGuardPlugin::class, exactly like
    // Filament's own Resource::make()/Page::make() convention.
    $bound = (new AzGuardPlugin)->forPanel('bound-instance');

    app()->bind(AzGuardPlugin::class, fn () => $bound);

    expect(AzGuardPlugin::make())->toBe($bound)
        ->and(AzGuardPlugin::make()->getPanelId())->toBe('bound-instance');
});

it('isEnforcing() falls back to config when enforce() was not called', function () {
    config(['az-guard-filament.enforce' => false]);

    expect(AzGuardPlugin::make()->isEnforcing())->toBeFalse();
});

it('enforce() overrides the config fallback', function () {
    config(['az-guard-filament.enforce' => true]);

    expect(AzGuardPlugin::make()->enforce(false)->isEnforcing())->toBeFalse();
});

it('getSource() falls back to config when source() was not called', function () {
    config(['az-guard-filament.source' => 'policy']);

    expect(AzGuardPlugin::make()->getSource())->toBe('policy');
});

it('source() overrides the config fallback', function () {
    config(['az-guard-filament.source' => 'database']);

    expect(AzGuardPlugin::make()->source('enum')->getSource())->toBe('enum');
});

it('getAbilities() falls back to config when abilities() was not called', function () {
    config(['az-guard-filament.abilities' => ['view']]);

    expect(AzGuardPlugin::make()->getAbilities())->toBe(['view']);
});

it('abilities() overrides the config fallback', function () {
    config(['az-guard-filament.abilities' => ['view']]);

    expect(AzGuardPlugin::make()->abilities(['view', 'create'])->getAbilities())
        ->toBe(['view', 'create']);
});

it('getKeyTemplate()/getCase() fall back to config when not called fluently', function () {
    config([
        'az-guard-filament.key' => '{resource}.{ability}',
        'az-guard-filament.case' => 'kebab',
    ]);

    expect(AzGuardPlugin::make()->getKeyTemplate())->toBe('{resource}.{ability}')
        ->and(AzGuardPlugin::make()->getCase())->toBe('kebab');
});

it('keyTemplate()/case() override the config fallback', function () {
    $plugin = AzGuardPlugin::make()
        ->keyTemplate('{panel}::{resource}::{ability}')
        ->case('camel');

    expect($plugin->getKeyTemplate())->toBe('{panel}::{resource}::{ability}')
        ->and($plugin->getCase())->toBe('camel');
});

it('register() writes the effective fluent options back into config as the fallback for other consumers', function () {
    config([
        'az-guard-filament.enforce' => true,
        'az-guard-filament.source' => 'database',
        'az-guard-filament.abilities' => ['view'],
        'az-guard-filament.key' => '{panel}.{resource}.{ability}',
        'az-guard-filament.case' => 'snake',
    ]);

    $panel = Mockery::mock(Panel::class);
    $panel->shouldReceive('resources')->once()->andReturnSelf();
    $panel->shouldReceive('pages')->once()->andReturnSelf();

    AzGuardPlugin::make()
        ->enforce(false)
        ->source('policy')
        ->abilities(['view', 'create'])
        ->keyTemplate('{resource}.{ability}')
        ->case('kebab')
        ->register($panel);

    expect(config('az-guard-filament.enforce'))->toBeFalse()
        ->and(config('az-guard-filament.source'))->toBe('policy')
        ->and(config('az-guard-filament.abilities'))->toBe(['view', 'create'])
        ->and(config('az-guard-filament.key'))->toBe('{resource}.{ability}')
        ->and(config('az-guard-filament.case'))->toBe('kebab');
});
