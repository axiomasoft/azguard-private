<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Extensions;

use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use AzGuard\Scopes\Contracts\AssignmentScopeMembership;
use AzGuard\Scopes\Diagnostics\ScopesHealthCheck;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Packman extension plugin declaring AzGuard Scopes capabilities and health diagnostics.
 */
#[Api(since: '1.0')]
final class ScopesExtensionPlugin implements ExtensionPlugin, WithCapabilities, WithDiagnostics
{
    use ProvidesCapabilities;
    use ProvidesDiagnostics;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.azguard-scopes',
            version: '2.0.0',
            resourceRoot: dirname(__DIR__, 2),
            databases: ['sqlite', 'pgsql', 'mysql', 'mariadb'],
        );
    }

    public function register(PluginRegistrar $registrar): void {}

    /** @return array<class-string, string> */
    public function definedCapabilities(): array
    {
        return [
            AssignmentScopeMembership::class => Cardinality::OptionalSingle->value,
            AssignmentScopeAccessAdapter::class => Cardinality::Many->value,
            AssignmentScopeFilter::class => Cardinality::Many->value,
        ];
    }

    /** @return array<class-string, class-string> */
    public function providedCapabilities(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function healthChecks(): array
    {
        return [ScopesHealthCheck::class];
    }
}
