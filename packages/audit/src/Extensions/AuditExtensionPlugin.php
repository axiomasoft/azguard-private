<?php

declare(strict_types=1);

namespace AzGuard\Audit\Extensions;

use AzGuard\Audit\Diagnostics\AuditHealthCheck;
use AzGuard\Audit\Schema\AuditSchemaSets;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Packman extension plugin declaring AzGuard Audit schema ownership and diagnostics.
 */
#[Api(since: '1.0')]
final class AuditExtensionPlugin implements ExtensionPlugin, WithDiagnostics, WithSchema
{
    use OwnsSchema;
    use ProvidesDiagnostics;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.azguard-audit',
            version: '2.0.0',
            resourceRoot: dirname(__DIR__, 2),
            databases: ['sqlite', 'pgsql', 'mysql', 'mariadb'],
        );
    }

    public function register(PluginRegistrar $registrar): void {}

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
