<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/*
 * I16 (D19, D20): fresh installs through the migrations an application runs. Core owns one migration with six tables;
 * the Audit journal has its own create-only migration, published under `azguard-audit-migrations` or generated per
 * named storage, and is never loaded by a provider. Runs on every driver (group `database`).
 */

const FRESH_CORE_TABLES = ['permissions', 'role_grants', 'permission_grants', 'panel_state', 'subject_revisions', 'storage_state'];

beforeEach(function (): void {
    $this->scratch = sys_get_temp_dir().'/azguard-fresh-'.bin2hex(random_bytes(6));
    mkdir($this->scratch.'/migrations', 0o755, true);
    app()->useDatabasePath($this->scratch);
    DoctorWorld::withoutPasswordInMessages();
});

afterEach(function (): void {
    Relation::morphMap([], false);
    Schema::dropIfExists('users');
    Artisan::call('migrate:reset');
    Schema::dropIfExists('migrations');
    (new Filesystem)->deleteDirectory($this->scratch);
});

/**
 * `vendor:publish --tag=<tag>` into the application of the test: the paths the providers registered for the tag,
 * with the database path of the application (bound at boot) replaced by the scratch database path.
 */
function freshPublish(string $tag): void
{
    $paths = ServiceProvider::pathsToPublish(null, $tag);

    expect($paths)->toHaveCount(1);

    foreach ($paths as $from => $to) {
        expect($to)->toEndWith('/database/migrations');

        foreach (glob($from.'/*.php') ?: [] as $file) {
            copy($file, test()->scratch.'/migrations/'.basename($file));
        }
    }
}

/** @return list<string> the migrations the database recorded, in order */
function freshMigrated(): array
{
    return DB::table('migrations')->orderBy('id')->pluck('migration')->all();
}

/** @return list<string> the tables of the prefix that exist, by base name */
function freshTables(string $prefix): array
{
    return array_values(array_filter([...FRESH_CORE_TABLES, 'audit_log'], static fn (string $base): bool => Schema::hasTable($prefix.$base)));
}

/** @return list<string> "key severity" of the doctor findings with the keys */
function freshFindings(string ...$keys): array
{
    $findings = array_filter(DoctorWorld::run(), static fn ($finding): bool => in_array($finding->key, $keys, true));

    return array_values(array_map(static fn ($finding): string => $finding->key.' '.$finding->severity->value.' '.$finding->message, $findings));
}

function freshAuditedPanel(string $storage = 'default'): void
{
    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)
        ->permissions([OrderPermission::class, DatabaseSource::make()->storage($storage)])->plugins([AuditPlugin::make()])]);
}

function freshGrant(): void
{
    Relation::morphMap(['user' => User::class], false);

    if (! Schema::hasTable('users')) {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
    }
    DB::table('users')->insert(['id' => 1]);
    AzGuard::panel('test')->for(SubjectRef::of('user', 1))->grantPermission('orders.view');
}

it('installs core only from the package-loaded migration: six tables, no journal table and no pending audit migration', function (): void {
    expect(Artisan::call('migrate'))->toBe(0)
        ->and(freshTables('azg_'))->toBe(FRESH_CORE_TABLES)
        ->and(freshMigrated())->toBe(['2026_10_01_000000_create_azguard_storage']);

    Artisan::call('migrate:status');

    expect(Artisan::output())->not->toContain('audit')
        ->and(freshFindings('storage.migrated', 'storage.schema'))->toBe([]);
});

it('installs core only from the published migration: the copy keeps the basename and runs once', function (): void {
    freshPublish('azguard-migrations');

    expect(array_map('basename', glob($this->scratch.'/migrations/*.php') ?: []))->toBe(['2026_10_01_000000_create_azguard_storage.php'])
        ->and(Artisan::call('migrate'))->toBe(0)
        ->and(freshTables('azg_'))->toBe(FRESH_CORE_TABLES)
        ->and(freshMigrated())->toBe(['2026_10_01_000000_create_azguard_storage']);
});

it('installs the journal of the default storage from the published audit migration; before that the doctor names the step', function (): void {
    Artisan::call('migrate');
    freshAuditedPanel();

    $diag = [];
    $walk = function (array $values, string $path, bool $secret) use (&$walk, &$diag): void {
        foreach ($values as $name => $value) {
            $named = $secret || preg_match('/pass(word)?|secret|token|dsn|url|credential|api[_-]?key|private/i', (string) $name) === 1;
            is_array($value) ? $walk($value, $path.'.'.$name, $named) : ($named && is_string($value) && str_contains($value, 'azguard') ? $diag[] = $path.'.'.$name.'='.$value : null);
        }
    };
    $walk((array) config('database.connections'), 'database.connections', false);
    $walk((array) config('azguard'), 'azguard', false);
    expect(['DIAG' => $diag, 'findings' => freshFindings('audit.table')])->toBe(['DIAG' => [], 'findings' => ['audit.table error The storage default of panel test has no audit_log table: run php artisan vendor:publish --tag=azguard-audit-migrations and php artisan migrate.']]);

    freshPublish('azguard-audit-migrations');

    expect(Artisan::call('migrate'))->toBe(0)
        ->and(freshTables('azg_'))->toBe([...FRESH_CORE_TABLES, 'audit_log'])
        ->and(freshMigrated())->toBe(['2026_10_01_000000_create_azguard_storage', '2026_10_20_000000_create_azguard_audit_log'])
        ->and(freshFindings('audit.table', 'storage.migrated', 'storage.schema'))->toBe([]);

    freshGrant();

    expect(DB::table('azg_audit_log')->pluck('type')->all())->toBe(['permission.granted']);
});

it('installs a named storage with its journal from the two generators', function (): void {
    config()->set('azguard.storages.ledger', ['connection' => null, 'table_prefix' => 'lgr_', 'host_keys' => null]);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(StorageRegistry::class);

    expect(Artisan::call('azguard:storage:migration', ['name' => 'ledger']))->toBe(0)
        ->and(Artisan::call('azguard:audit:migration', ['storage' => 'ledger']))->toBe(0)
        ->and(Artisan::call('migrate'))->toBe(0)
        ->and(freshTables('lgr_'))->toBe([...FRESH_CORE_TABLES, 'audit_log'])
        ->and(freshTables('azg_'))->toBe(FRESH_CORE_TABLES);

    freshAuditedPanel('ledger');
    freshGrant();

    expect(DB::table('lgr_audit_log')->pluck('type')->all())->toBe(['permission.granted'])
        ->and(DB::table('lgr_permission_grants')->count())->toBe(1)
        ->and(DB::table('azg_permission_grants')->count())->toBe(0)
        ->and(freshFindings('audit.table'))->toBe([]);
});

it('rolls back: the audit migration drops the journal, the core migration its six tables, a table of another owner stays', function (): void {
    Artisan::call('migrate');
    freshPublish('azguard-audit-migrations');
    Artisan::call('migrate');
    Schema::create('azg_host_notes', static function (Blueprint $table): void {
        $table->id();
    });

    try {
        expect(Artisan::call('migrate:rollback', ['--step' => 1]))->toBe(0)
            ->and(freshTables('azg_'))->toBe(FRESH_CORE_TABLES)
            ->and(Artisan::call('migrate:rollback', ['--step' => 1]))->toBe(0)
            ->and(freshTables('azg_'))->toBe([])
            ->and(Schema::hasTable('azg_host_notes'))->toBeTrue();
    } finally {
        Schema::dropIfExists('azg_host_notes');
    }
});
