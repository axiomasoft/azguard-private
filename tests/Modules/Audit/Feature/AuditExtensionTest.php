<?php

declare(strict_types=1);

use AzGuard\Audit\AuditServiceProvider;
use AzGuard\Audit\Diagnostics\AuditHealthCheck;
use AzGuard\Audit\Extensions\AuditExtensionPlugin;
use AzGuard\Audit\Schema\AuditSchemaSets;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\ServiceProvider;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Extensions\ExtensionInspector;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;

it('declares AuditServiceProvider as final without auto-loading migrations', function (): void {
    $reflection = new ReflectionClass(AuditServiceProvider::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isSubclassOf(ServiceProvider::class))->toBeTrue()
        ->and(app()->getProvider(AuditServiceProvider::class))->toBeInstanceOf(AuditServiceProvider::class);

    $migrator = app('migrator');
    expect($migrator)->toBeInstanceOf(Migrator::class);

    $auditMigrationDir = realpath(dirname(__DIR__, 4).'/packages/audit/database/migrations');
    $registeredPaths = array_map(
        static fn (string $path): string => realpath($path) ?: $path,
        $migrator->paths(),
    );

    expect($auditMigrationDir)->not->toBeFalse()
        ->and($registeredPaths)->not->toContain($auditMigrationDir);
});

it('registers AzGuard Audit Packman plugin facets, schema ownership, schema sets, and diagnostics', function (): void {
    config()->set('packman.plugins', [AuditExtensionPlugin::class]);

    $plugin = new AuditExtensionPlugin;
    $profile = ExtensionInspector::inspect($plugin);

    expect($plugin->manifest()->id)->toBe('axiomasoft.azguard-audit')
        ->and($profile->persistsInDatabase)->toBeFalse()
        ->and($profile->hasSchema)->toBeTrue()
        ->and($profile->tablePrefix)->toBe('azg_')
        ->and($profile->tableNouns)->toBe(['audit_log'])
        ->and($profile->schemaSetProviders)->toBe([AuditSchemaSets::class])
        ->and($profile->healthChecks)->toBe([AuditHealthCheck::class]);

    $inspected = app(PluginInspector::class)->facetsOf('axiomasoft.azguard-audit');
    expect($inspected)->toBeInstanceOf(ExtensionFacetProfile::class)
        ->and($inspected?->tableNouns)->toBe(['audit_log']);

    $ownership = app(SchemaOwnership::class);
    expect($ownership->ownerOf('audit_log'))->toBe('axiomasoft.azguard-audit')
        ->and($ownership->ownerOf('audit_logs'))->toBe('axiomasoft.azguard-audit');

    $schemaSet = app(SchemaSets::class)->get('axiomasoft/azguard-audit:azguard-audit-log');
    expect($schemaSet->scope)->toBe(SchemaScope::Central)
        ->and($schemaSet->lifecycle)->toBe(SchemaLifecycle::Module)
        ->and(is_dir($schemaSet->path))->toBeTrue();

    $checks = app(DoctorRegistryContract::class)->matching(['azguard.audit.health']);
    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toBeInstanceOf(AuditHealthCheck::class);

    $findings = $checks[0]->run(new DoctorContext);
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(CheckStatus::Ok)
        ->and($findings[0]->code)->toBe('azguard.audit.health');
});
