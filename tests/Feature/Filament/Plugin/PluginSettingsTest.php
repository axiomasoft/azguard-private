<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConfigurationException;
use AzGuard\Filament\AzGuardFilamentServiceProvider;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Kernel\Decision\PermissionAuthority;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Config\Repository;
use Illuminate\Support\ServiceProvider;

/*
 * The plugin keeps its settings in the instance; the configuration gives the defaults and is read once.
 */

it('merges the configuration of the package and publishes it with a tag', function (): void {
    expect(config('azguard-filament'))->toMatchArray([
        'guard_panel' => null,
        'manages' => null,
        'enforce' => true,
        'definitions' => 'enums',
        'authority' => 'grants',
    ])
        ->and(config('azguard-filament.abilities'))->toBe(['view_any', 'view', 'create', 'update', 'delete', 'delete_any', 'force_delete', 'force_delete_any', 'restore', 'restore_any', 'replicate', 'reorder'])
        ->and(config('azguard-filament.exclude'))->toBe(['resources' => [], 'pages' => [], 'widgets' => []])
        ->and(array_values(ServiceProvider::pathsToPublish(AzGuardFilamentServiceProvider::class, 'azguard-filament-config')))->toBe([config_path('azguard-filament.php')])
        ->and(array_keys(ServiceProvider::pathsToPublish(AzGuardFilamentServiceProvider::class, 'azguard-filament-config'))[0])->toEndWith('/config/azguard-filament.php');
});

it('takes the defaults from the configuration and lets the instance win', function (): void {
    config([
        'azguard-filament.guard_panel' => 'from-config',
        'azguard-filament.manages' => ['a', 'b'],
        'azguard-filament.enforce' => false,
        'azguard-filament.definitions' => 'resources',
        'azguard-filament.authority' => 'policy',
        'azguard-filament.abilities' => ['view_any'],
        'azguard-filament.exclude' => ['widgets' => ['X']],
    ]);

    $plugin = AzGuardPlugin::make();

    expect($plugin->getGuardPanel())->toBe('from-config')
        ->and($plugin->getManages())->toBe(['a', 'b'])
        ->and($plugin->isEnforced())->toBeFalse()
        ->and($plugin->getDefinitions())->toBe(FilamentDefinitions::Resources)
        ->and($plugin->getAuthority())->toBe(PermissionAuthority::Policy)
        ->and($plugin->getAbilities())->toBe(['view_any'])
        ->and($plugin->getExclude())->toBe(['resources' => [], 'pages' => [], 'widgets' => ['X']]);

    $plugin->guardPanel('own')->manages(null)->enforce()->definitions(FilamentDefinitions::Enums)->authority(PermissionAuthority::Grants)->abilities(['view']);

    expect($plugin->getGuardPanel())->toBe('own')
        ->and($plugin->getManages())->toBeNull()
        ->and($plugin->isEnforced())->toBeTrue()
        ->and($plugin->getDefinitions())->toBe(FilamentDefinitions::Enums)
        ->and($plugin->getAuthority())->toBe(PermissionAuthority::Grants)
        ->and($plugin->getAbilities())->toBe(['view'])
        ->and(config('azguard-filament.guard_panel'))->toBe('from-config');
});

it('makes a new instance with every make() and has the id azguard', function (): void {
    expect(AzGuardPlugin::make())->not->toBe(AzGuardPlugin::make())
        ->and(AzGuardPlugin::make()->getId())->toBe('azguard');
});

it('stores the editor flags and the form extensions', function (): void {
    $plugin = AzGuardPlugin::make();

    expect($plugin->hasEditor('roles'))->toBeTrue()
        ->and($plugin->hasEditor('doctor'))->toBeTrue()
        ->and($plugin->hasEditor('unknown'))->toBeFalse();

    $plugin->resources(roles: false, doctor: false)->formExtensions('A\\One', 'A\\Two')->formExtensions('A\\One');

    expect($plugin->hasEditor('roles'))->toBeFalse()
        ->and($plugin->hasEditor('panels'))->toBeTrue()
        ->and($plugin->hasEditor('doctor'))->toBeFalse()
        ->and($plugin->getFormExtensions())->toBe(['A\\One', 'A\\Two']);
});

it('says what to do when no guard panel is set', function (): void {
    expect(fn () => AzGuardPlugin::make()->getGuardPanel())
        ->toThrow(ConfigurationException::class, "call guardPanel('id') or set guard_panel in config/azguard-filament.php");
});

it('refuses a configuration value it cannot use', function (array $config, string $message): void {
    expect(fn () => new AzGuardPlugin(new Repository(['azguard-filament' => $config])))
        ->toThrow(ConfigurationException::class, $message);
})->with([
    'definitions' => [['definitions' => 'models'], 'definitions must be'],
    'authority' => [['authority' => 'both'], 'authority must be'],
    'abilities not snake_case' => [['abilities' => ['viewAny']], 'is not snake_case'],
    'abilities not a list' => [['abilities' => 'view'], 'abilities must be a list of strings'],
    'exclude with a number' => [['exclude' => ['pages' => [1]]], 'exclude.pages must be a list of non-empty strings'],
    'guard panel not a string' => [['guard_panel' => 5], 'guard_panel must be'],
]);

it('refuses an authority that is not grants or policy', function (): void {
    expect(fn () => AzGuardPlugin::make()->authority('both'))->toThrow(ConfigurationException::class, 'authority must be');
});

it('finds the plugin of a Filament panel and says when a panel has none', function (): void {
    expect(AzGuardPlugin::get('backoffice')->getGuardPanel())->toBe('backoffice');

    Filament::registerPanel(Panel::make()->id('bare')->path('bare'));

    expect(fn () => AzGuardPlugin::get('bare'))->toThrow(ConfigurationException::class, 'does not have AzGuardPlugin');
});

it('checks at boot that the guard panel and the managed panels are registered', function (string $guard, array $manages, string $message): void {
    $panel = Filament::getPanel('backoffice');
    $plugin = AzGuardPlugin::get('backoffice')->guardPanel($guard)->manages($manages);

    expect(fn () => $plugin->boot($panel))->toThrow(ConfigurationException::class, $message);
})->with([
    'guard panel' => ['ghost', ['backoffice'], 'names the AzGuard panel "ghost", which is not registered'],
    'managed panel' => ['backoffice', ['backoffice', 'ghost'], 'manages the AzGuard panel "ghost", which is not registered'],
]);
