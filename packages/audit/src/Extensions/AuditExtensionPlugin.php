<?php

declare(strict_types=1);

namespace AzGuard\Audit\Extensions;

use AzGuard\Audit\AuditOptions;
use AzGuard\Audit\Diagnostics\AuditHealthCheck;
use AzGuard\Audit\Schema\AuditSchemaSets;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Packman extension plugin declaring AzGuard Audit schema ownership, configuration, and diagnostics.
 */
#[Api(since: '1.0')]
final class AuditExtensionPlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesDiagnostics;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.azguard-audit',
            version: '2.0.0',
            optional: ['axiomasoft.azguard'],
            resourceRoot: dirname(__DIR__, 2),
            databases: ['sqlite', 'pgsql', 'mysql', 'mariadb'],
        );
    }

    public function register(PluginRegistrar $registrar): void {}

    public function configRoot(): string
    {
        return 'azguard-audit';
    }

    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/azguard-audit.php';
    }

    /** @return class-string<AuditOptions> */
    public function optionsClass(): string
    {
        return AuditOptions::class;
    }

    public function tablePrefix(): string
    {
        return 'azg_';
    }

    /** @return list<string> */
    public function tableNouns(): array
    {
        return ['audit_log'];
    }

    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    /** @return list<class-string> */
    public function schemaSetProviders(): array
    {
        return [AuditSchemaSets::class];
    }

    /** @return list<class-string> */
    public function healthChecks(): array
    {
        return [AuditHealthCheck::class];
    }
}
