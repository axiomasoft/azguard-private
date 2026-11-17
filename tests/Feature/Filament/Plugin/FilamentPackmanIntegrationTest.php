<?php

declare(strict_types=1);

use AzGuard\Exceptions\Configuration\InvalidConfigurationException;
use AzGuard\Filament\Authorization\AzGuardFilamentSurfaceGate;
use AzGuard\Filament\AzGuardFilamentServiceProvider;
use AzGuard\Filament\Configuration\AzGuardFilamentOptions;
use AzGuard\Filament\Contracts\FilamentFormExtension;
use AzGuard\Filament\Diagnostics\AzGuardFilamentHealthCheck;
use AzGuard\Filament\Extensions\AzGuardFilamentPlugin;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Kernel\Decision\PermissionAuthority;
use Illuminate\Support\ServiceProvider;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Extensions\ExtensionInspector;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Enums\Contributions\Cardinality;

it('registers AzGuardFilamentPlugin facets, surface gate binding, and health diagnostics', function (): void {
    config()->set('packman.plugins', [AzGuardFilamentPlugin::class]);

    $reflection = new ReflectionClass(AzGuardFilamentServiceProvider::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isSubclassOf(ServiceProvider::class))->toBeTrue()
        ->and(app()->getProvider(AzGuardFilamentServiceProvider::class))->toBeInstanceOf(AzGuardFilamentServiceProvider::class);

    $plugin = new AzGuardFilamentPlugin;
    $profile = ExtensionInspector::inspect($plugin);

    expect($plugin->manifest()->id)->toBe('axiomasoft.azguard-filament')
        ->and($profile->persistsInDatabase)->toBeFalse()
        ->and($profile->hasConfiguration)->toBeTrue()
        ->and($profile->configRoot)->toBe('azguard-filament')
        ->and($profile->optionsClass)->toBe(AzGuardFilamentOptions::class)
        ->and($profile->definedCapabilities)->toBe([
            FilamentSurfaceGate::class => Cardinality::RequiredSingle->value,
            FilamentFormExtension::class => Cardinality::Many->value,
        ])
        ->and($profile->providedCapabilities)->toBe([
            FilamentSurfaceGate::class => AzGuardFilamentSurfaceGate::class,
        ])
        ->and($profile->healthChecks)->toBe([AzGuardFilamentHealthCheck::class]);

    $inspected = app(PluginInspector::class)->facetsOf('axiomasoft.azguard-filament');
    expect($inspected)->toBeInstanceOf(ExtensionFacetProfile::class)
        ->and($inspected?->optionsClass)->toBe(AzGuardFilamentOptions::class);

    $capabilities = app(Capabilities::class);
    expect($capabilities->has(FilamentSurfaceGate::class))->toBeTrue()
        ->and($capabilities->require(FilamentSurfaceGate::class))->toBeInstanceOf(AzGuardFilamentSurfaceGate::class)
        ->and(app(FilamentSurfaceGate::class))->toBeInstanceOf(AzGuardFilamentSurfaceGate::class);

    $checks = app(DoctorRegistryContract::class)->matching(['azguard.filament.health']);
    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toBeInstanceOf(AzGuardFilamentHealthCheck::class);

    $findings = $checks[0]->run(new DoctorContext);
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(CheckStatus::Ok)
        ->and($findings[0]->code)->toBe('azguard.filament.health')
        ->and($findings[0]->evidence['filament_panels'] ?? 0)->toBeGreaterThan(0);
});

it('parses and validates AzGuardFilamentOptions cleanly', function (): void {
    $options = AzGuardFilamentOptions::fromArray([
        'guard_panel' => 'admin',
        'manages' => ['users', 'orders'],
        'enforce' => false,
        'definitions' => 'resources',
        'authority' => 'policy',
        'abilities' => ['view_any', 'create'],
        'exclude' => [
            'resources' => ['App\\Filament\\Resources\\AuditResource'],
            'pages' => [],
            'widgets' => [],
        ],
    ], 'azguard-filament');

    expect($options->guardPanel)->toBe('admin')
        ->and($options->manages)->toBe(['users', 'orders'])
        ->and($options->enforce)->toBeFalse()
        ->and($options->definitions)->toBe(FilamentDefinitions::Resources)
        ->and($options->authority)->toBe(PermissionAuthority::Policy)
        ->and($options->abilities)->toBe(['view_any', 'create'])
        ->and($options->exclude['resources'])->toBe(['App\\Filament\\Resources\\AuditResource']);

    expect(static fn () => AzGuardFilamentOptions::fromArray(['definitions' => 'invalid'], 'azguard-filament'))
        ->toThrow(InvalidConfigurationException::class);

    expect(static fn () => AzGuardFilamentOptions::fromArray(['authority' => 'invalid'], 'azguard-filament'))
        ->toThrow(InvalidConfigurationException::class);

    expect(static fn () => AzGuardFilamentOptions::fromArray(['abilities' => ['NotSnakeCase']], 'azguard-filament'))
        ->toThrow(InvalidConfigurationException::class);
});
