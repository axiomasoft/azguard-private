<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Diagnostics\ColumnRoleGrant;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Checks of each storage: its tables, its state row and host key columns, the columns of the own models of a database
 * source and the journal table of the audit plugin. An unreachable or unreadable storage is an error, never a skip.
 */

/** @param Closure(PanelBuilder): mixed|null $more */
function storagePanel(?DatabaseSource $source = null, ?Closure $more = null): void
{
    DoctorWorld::panels([TestPanel::class => static function (PanelBuilder $panel) use ($source, $more): void {
        $panel->for(User::class)->permissions([OrderPermission::class, $source ?? DatabaseSource::make()]);

        if ($more !== null) {
            $more($panel);
        }
    }]);
}

/** @return list<string> */
function storageFindings(string $key): array
{
    return array_map(static fn ($finding): string => $finding->scope.' '.$finding->severity->value.' '.$finding->message, DoctorWorld::only(DoctorWorld::run(), $key));
}

it('storage.migrated passes a migrated storage and fails one without its tables', function (): void {
    storagePanel();
    expect(storageFindings('storage.migrated'))->toBe(['storage:default error Storage default lacks the tables permissions, role_grants, permission_grants, audit_log, panel_state, storage_state (prefix "azg_"): publish and run the AzGuard migrations.']);

    DoctorWorld::migrate();
    expect(storageFindings('storage.migrated'))->toBe([]);

    Schema::drop('azg_audit_log');
    expect(storageFindings('storage.migrated'))->toBe(['storage:default error Storage default lacks the tables audit_log (prefix "azg_"): publish and run the AzGuard migrations.']);
});

it('storage.schema passes a matching state row and fails a different or unreadable one, also after an earlier successful read', function (): void {
    DoctorWorld::migrate();
    storagePanel();
    $storage = app(StorageRegistry::class)->get('default');
    $storage->state('test');
    expect(storageFindings('storage.schema'))->toBe([]);

    $storage->table('storage_state')->where('id', 1)->update(['schema' => json_encode([...$storage->schema(), 'prefix' => 'old_'], JSON_THROW_ON_ERROR)]);
    expect(storageFindings('storage.schema'))->toHaveCount(1)
        ->and(storageFindings('storage.schema')[0])->toContain('storage:default error Storage default expected')->toContain('"prefix":"old_"');

    Schema::drop('azg_storage_state');
    expect(storageFindings('storage.schema'))->toBe(['storage:default error Storage default cannot read storage_state (Illuminate\Database\QueryException); the schema cannot be verified.']);
});

it('storage.schema fails host key columns that cannot hold the host keys of the storage', function (): void {
    DoctorWorld::migrate();
    config(['azguard.ids.host_keys' => 'bigint']);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(StorageRegistry::class);
    $storage = app(StorageRegistry::class)->get('default');
    $storage->table('storage_state')->where('id', 1)->update(['schema' => json_encode($storage->schema(), JSON_THROW_ON_ERROR)]);
    storagePanel();

    $finding = DoctorWorld::only(DoctorWorld::run(), 'storage.schema');
    expect($finding)->toHaveCount(1)
        ->and($finding[0]->details['host_keys'])->toBe('bigint')
        ->and($finding[0]->details['columns'])->toContain('role_grants.subject_id', 'permission_grants.actor_id', 'audit_log.tenant_id');
});

it('storage.schema of a database source fails a column field its model declares that the table lacks', function (): void {
    DoctorWorld::migrate();
    storagePanel(DatabaseSource::make()->models(roleGrant: ColumnRoleGrant::class));
    expect(storageFindings('storage.schema'))->toBe(['panel:test error Table azg_role_grants lacks the columns region of the fields of '.ColumnRoleGrant::class.': add them in a migration of the application.']);

    Schema::table('azg_role_grants', static fn (Blueprint $table) => $table->string('region')->nullable());
    expect(storageFindings('storage.schema'))->toBe([]);
});

it('audit.table passes a migrated journal and fails a missing journal or a panel without a database writer', function (): void {
    DoctorWorld::migrate();
    storagePanel(more: static fn (PanelBuilder $panel) => $panel->plugins([AuditPlugin::make()]));
    expect(storageFindings('audit.table'))->toBe([]);

    Schema::drop('azg_audit_log');
    $finding = DoctorWorld::only(DoctorWorld::run(), 'audit.table');
    expect(DoctorWorld::summary($finding))->toBe(['plugin:azguard/audit audit.table error'])
        ->and($finding[0]->details)->toBe(['panel' => 'test', 'storage' => 'default']);

    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)->permissions([OrderPermission::class])
        ->plugins([AuditPlugin::make()])]);
    expect(storageFindings('audit.table'))->toBe(['plugin:azguard/audit error Panel test has the audit plugin but no database source that stores its grants, so no journal is written.']);
});
