<?php

declare(strict_types=1);

use AzGuard\DevTools\DevToolsServiceProvider;
use AzGuard\DevTools\Extensions\DevToolsExtensionPlugin;
use AzGuard\Exceptions\Configuration\InvalidConfigurationException;
use AzGuard\Extensions\AzGuardCorePlugin;
use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Extensions\ExtensionInspector;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Testing\PluginGraph;

it('declares DevToolsServiceProvider as a final registered Laravel service provider', function (): void {
    $reflection = new ReflectionClass(DevToolsServiceProvider::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isSubclassOf(ServiceProvider::class))->toBeTrue()
        ->and(app()->getProvider(DevToolsServiceProvider::class))->toBeInstanceOf(DevToolsServiceProvider::class);
});

it('registers AzGuard DevTools Packman facets and owns the scaffold config section', function (): void {
    $plugin = new DevToolsExtensionPlugin;
    $profile = ExtensionInspector::inspect($plugin);

    expect($plugin->manifest()->id)->toBe('axiomasoft.azguard-devtools')
        ->and($profile->persistsInDatabase)->toBeFalse()
        ->and($profile->hasSchema)->toBeFalse();

    $inspected = app(PluginInspector::class)->facetsOf('axiomasoft.azguard-devtools');
    expect($inspected)->toBeInstanceOf(ExtensionFacetProfile::class);

    $owned = ConfigSectionRegistration::owned(app());
    expect(array_keys($owned))->toContain('scaffold');

    ConfigSectionRegistration::assertKnownKeys(app(), ['scaffold' => []]);

    expect(static fn () => ConfigSectionRegistration::assertKnownKeys(app(), ['unknown_root' => []]))
        ->toThrow(InvalidConfiguration::class);

    expect(static fn () => ConfigSectionRegistration::assertKnownKeys(app(), ['scaffold' => ['unexpected' => true]]))
        ->toThrow(InvalidConfigurationException::class);

    $compiled = PluginGraph::compile(new Container, [AzGuardCorePlugin::class, DevToolsExtensionPlugin::class]);
    expect(array_map(static fn ($d): string => $d->id, $compiled->describe()))
        ->toContain('axiomasoft.azguard', 'axiomasoft.azguard-devtools');
});
