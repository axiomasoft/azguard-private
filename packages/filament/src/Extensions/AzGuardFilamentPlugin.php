<?php

declare(strict_types=1);

namespace AzGuard\Filament\Extensions;

use AzGuard\Filament\Authorization\AzGuardFilamentSurfaceGate;
use AzGuard\Filament\Configuration\AzGuardFilamentOptions;
use AzGuard\Filament\Contracts\FilamentFormExtension;
use AzGuard\Filament\Diagnostics\AzGuardFilamentHealthCheck;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Packman extension plugin declaring AzGuard Filament configuration, surface gate capabilities, and health diagnostics.
 */
#[Api(since: '1.0')]
final class AzGuardFilamentPlugin implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDiagnostics
{
    use Configurable;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.azguard-filament',
            version: '2.0.0',
            resourceRoot: dirname(__DIR__, 2),
            databases: ['sqlite', 'pgsql', 'mysql', 'mariadb'],
        );
    }

    public function register(PluginRegistrar $registrar): void {}

    public function configRoot(): string
    {
        return 'azguard-filament';
    }

    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/azguard-filament.php';
    }

    /** @return class-string<AzGuardFilamentOptions> */
    public function optionsClass(): string
    {
        return AzGuardFilamentOptions::class;
    }

    /** @return array<class-string, string> */
    public function definedCapabilities(): array
    {
        return [
            FilamentSurfaceGate::class => Cardinality::RequiredSingle->value,
            FilamentFormExtension::class => Cardinality::Many->value,
        ];
    }

    /** @return array<class-string, class-string> */
    public function providedCapabilities(): array
    {
        return [
            FilamentSurfaceGate::class => AzGuardFilamentSurfaceGate::class,
        ];
    }

    /** @return list<class-string> */
    public function healthChecks(): array
    {
        return [AzGuardFilamentHealthCheck::class];
    }
}
