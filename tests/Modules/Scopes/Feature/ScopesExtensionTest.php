<?php

declare(strict_types=1);

use AzGuard\Extensions\AzGuardCorePlugin;
use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use AzGuard\Scopes\Contracts\AssignmentScopeMembership;
use AzGuard\Scopes\Diagnostics\ScopesHealthCheck;
use AzGuard\Scopes\Extensions\ScopesExtensionPlugin;
use AzGuard\Scopes\ScopesOptions;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Scopes\ScopesServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Extensions\ExtensionInspector;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Testing\PluginGraph;

it('declares ScopesServiceProvider as a final registered Laravel service provider', function (): void {
    $reflection = new ReflectionClass(ScopesServiceProvider::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isSubclassOf(ServiceProvider::class))->toBeTrue()
        ->and(app()->getProvider(ScopesServiceProvider::class))->toBeInstanceOf(ScopesServiceProvider::class);
});

it('exposes AzGuard Scopes Packman plugin facets, capabilities, and diagnostics cleanly', function (): void {
    $plugin = new ScopesExtensionPlugin;
    $profile = ExtensionInspector::inspect($plugin);

    expect($plugin->manifest()->id)->toBe('axiomasoft.azguard-scopes')
        ->and($profile->persistsInDatabase)->toBeFalse()
        ->and($profile->hasSchema)->toBeFalse()
        ->and($profile->definedCapabilities)->toBe([
            AssignmentScopeMembership::class => Cardinality::OptionalSingle->value,
            AssignmentScopeAccessAdapter::class => Cardinality::Many->value,
            AssignmentScopeFilter::class => Cardinality::Many->value,
        ])
        ->and($profile->providedCapabilities)->toBe([])
        ->and($profile->healthChecks)->toBe([ScopesHealthCheck::class]);

    $inspected = app(PluginInspector::class)->facetsOf('axiomasoft.azguard-scopes');
    expect($inspected)->toBeInstanceOf(ExtensionFacetProfile::class)
        ->and($inspected?->healthChecks)->toBe([ScopesHealthCheck::class]);

    $checks = app(DoctorRegistryContract::class)->matching(['azguard.scopes.health']);
    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toBeInstanceOf(ScopesHealthCheck::class);

    $findings = $checks[0]->run(new DoctorContext);
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(CheckStatus::Ok)
        ->and($findings[0]->code)->toBe('azguard.scopes.health');

    $compiled = PluginGraph::compile(new Container, [AzGuardCorePlugin::class, ScopesExtensionPlugin::class]);
    $options = ScopesOptions::fromArray(['membership' => null, 'adapters' => []], 'azguard-scopes');

    expect(ScopesPlugin::inherit()->extension())->toBeInstanceOf(ScopesExtensionPlugin::class)
        ->and(array_map(static fn ($d): string => $d->id, $compiled->describe()))->toContain('axiomasoft.azguard', 'axiomasoft.azguard-scopes')
        ->and($options->membership)->toBeNull()
        ->and($options->adapters)->toBe([])
        ->and(static fn () => ScopesOptions::fromArray(['unknown' => true], 'azguard-scopes'))->toThrow(InvalidConfiguration::class)
        ->and(static fn () => ScopesOptions::fromArray(['membership' => stdClass::class], 'azguard-scopes'))->toThrow(InvalidConfiguration::class)
        ->and(static fn () => ScopesOptions::fromArray(['adapters' => ['invalid']], 'azguard-scopes'))->toThrow(InvalidConfiguration::class);
});
