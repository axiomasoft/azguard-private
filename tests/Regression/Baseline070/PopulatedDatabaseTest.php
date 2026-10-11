<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Baseline070\Formats\FormatSnapshot;
use AzGuard\Tests\Fixtures\Baseline070\Persistence\Dataset;
use AzGuard\Tests\Fixtures\Baseline070\Persistence\Legacy070Schema;
use AzGuard\Tests\Fixtures\Baseline070\Persistence\Restorer;
use AzGuard\Tests\Fixtures\Baseline070\Persistence\Scenario;
use AzGuard\Tests\Fixtures\Baseline070\Persistence\Writer;
use AzGuard\Tests\Fixtures\Storage\DdlSnapshot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;

/*
 * I16, blocking now: populated 0.7.0 databases per driver and scenario (default storage, named storage, published
 * core migration copies, schema 1) restore without 0.7.0 production code and keep their DDL, audit_log shape, rows and
 * migrations table, and the current code accepts them. P3 proves against them that the split rewrites no row.
 *
 * A dump is written only from the unchanged 0.7.0 code with AZGUARD_BASELINE_WRITE=1 (Persistence\Writer); a normal
 * run is read-only and fails when a dump is missing.
 */

beforeEach(function (): void {
    $this->scratch = sys_get_temp_dir().'/azguard-baseline-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->scratch);
    Relation::morphMap([], false);
    Carbon::setTestNow();
    Dataset::releaseUlids();
});

/**
 * The DDL of a dumped table as the shared DDL fixture (`tests/Fixtures/Storage/ddl`) records it: without the
 * application columns and with the default table prefix.
 *
 * @param  array<string, mixed>  $entry
 * @return array<string, mixed>
 */
function baselineCoreDdl(array $entry, string $base): array
{
    $ddl = $entry['ddl'][$base];
    $application = $entry['application_columns'][$base] ?? [];
    $ddl['columns'] = array_values(array_filter($ddl['columns'], static fn (array $column): bool => ! in_array($column['name'], $application, true)));

    return json_decode(str_replace($entry['prefix'], 'azg_', json_encode($ddl, JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The full shape of audit_log, named part by part: columns with types and nullability, the unique `event_id`, the
 * secondary indexes, the tenant pair constraint (SQLite: its two triggers) and the host-key column types.
 *
 * @param  array<string, mixed>  $ddl
 */
function baselineAssertAuditShape(array $ddl, string $prefix, string $driver): void
{
    $columns = array_column($ddl['columns'], null, 'name');
    $indexes = array_column($ddl['indexes'], null, 'name');

    expect(array_keys($columns))->toBe(['id', 'event_id', 'type', 'panel', 'tenant_key', 'tenant_type', 'tenant_id', 'subject_type', 'subject_id',
        'actor_type', 'actor_id', 'actor_reason', 'correlation_id', 'payload', 'occurred_at'])
        ->and(array_keys(array_filter($columns, static fn (array $column): bool => $column['nullable'])))
        ->toBe(['tenant_type', 'tenant_id', 'subject_type', 'subject_id', 'actor_type', 'actor_id', 'actor_reason'])
        ->and($indexes[$prefix.'al_event'])->toMatchArray(['columns' => ['event_id'], 'unique' => true])
        ->and($indexes[$prefix.'al_time'])->toMatchArray(['columns' => ['panel', 'occurred_at'], 'unique' => false])
        ->and($indexes[$prefix.'al_subject'])->toMatchArray(['columns' => ['panel', 'tenant_key', 'subject_type', 'subject_id'], 'unique' => false])
        ->and($indexes[$prefix.'al_corr'])->toMatchArray(['columns' => ['correlation_id'], 'unique' => false])
        ->and($ddl['foreign_keys'])->toBe([]);
    expect(array_column($ddl['constraints'], 'name'))->toBe(match ($driver) {
        'sqlite' => [$prefix.'al_tenant_pair_insert', $prefix.'al_tenant_pair_update'],
        // MariaDB adds a json_valid() check named after each JSON column.
        'mariadb' => [$prefix.'al_tenant_pair', 'payload'],
        default => [$prefix.'al_tenant_pair'],
    });

    // Host keys are strings at 0.7.0: identifier columns (binary on MySQL/MariaDB, C collation on PostgreSQL).
    foreach (['tenant_id', 'subject_id', 'actor_id'] as $hostKey) {
        expect($columns[$hostKey]['type'])->toBe(match ($driver) {
            'pgsql' => 'character varying(64)',
            'sqlite' => 'varchar',
            default => 'varbinary(64)',
        });
    }
}

it('freezes the 0.7.0 DDL: Legacy070Schema creates the tables the production schema creates', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $production = app(StorageSchema::class);
    $legacy = new Legacy070Schema($storage->connection(), 'default', $storage->prefix());
    $tables = array_combine(Legacy070Schema::TABLES, Legacy070Schema::TABLES);

    $production->drop('default');
    $production->create('default');
    $expected = array_map(static fn (string $base): array => DdlSnapshot::table($storage, $base), $tables);
    $productionState = $storage->table('storage_state')->get()->all();
    $production->drop('default');

    $legacy->create();
    $actual = array_map(static fn (string $base): array => DdlSnapshot::table($storage, $base), $tables);
    $legacyState = $storage->table('storage_state')->get()->all();
    $legacy->drop();

    expect($actual)->toBe($expected)
        ->and($legacyState)->toEqual($productionState);
});

it('restores the populated 0.7.0 database of the scenario and the current code accepts it', function (string $scenario): void {
    $connection = app('db')->connection();
    $driver = $connection->getDriverName();

    if (getenv(FormatSnapshot::WRITE) === '1') {
        Writer::write($scenario, $this->scratch.'/writer');
        $connection->getSchemaBuilder()->dropAllTables();
    }
    $dump = Restorer::load($driver, $scenario);
    Restorer::configure(array_keys($dump['storages']));
    Scenario::prepare($scenario, $this->scratch.'/reader');
    Restorer::restore($dump, $connection);
    $reference = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Storage/ddl/'.$driver.'.json'), true, flags: JSON_THROW_ON_ERROR)['string'];

    expect($dump['header'])->toMatchArray(['baseline' => '0.7.0', 'commit' => Restorer::BASELINE, 'driver' => $driver, 'scenario' => $scenario])
        ->and(array_keys($dump['storages']))->toBe($scenario === 'named' ? ['default', Restorer::NAMED['id']] : ['default']);

    foreach ($dump['storages'] as $id => $entry) {
        $storage = app(StorageRegistry::class)->get($id);

        foreach (Legacy070Schema::TABLES as $base) {
            expect(Restorer::ddl($storage, $base))->toBe($entry['ddl'][$base], $id.'.'.$base.' DDL')
                ->and(baselineCoreDdl($entry, $base))->toBe(Restorer::uniqueConstraints($reference[$base]), $id.'.'.$base.' DDL against tests/Fixtures/Storage/ddl')
                ->and(Restorer::rows($connection, $entry['prefix'], $base))->toBe($entry['tables'][$base], $id.'.'.$base.' rows');
        }
        baselineAssertAuditShape(Restorer::ddl($storage, 'audit_log'), $entry['prefix'], $driver);
        $storage->verifySchema();
    }

    $applicationMigrations = match ($scenario) {
        'published' => [Scenario::CREATE, Scenario::UPGRADE],
        'named' => [Scenario::NAMED_MIGRATION],
        default => [],
    };
    expect(Restorer::migrations($connection))->toBe($dump['migrations'])
        ->and($dump['migrations'])->toBe(match ($scenario) {
            'named' => [['migration' => Scenario::CREATE, 'batch' => 1], ['migration' => Scenario::UPGRADE, 'batch' => 1], ['migration' => Scenario::NAMED_MIGRATION, 'batch' => 1]],
            'schema1' => [['migration' => Scenario::CREATE, 'batch' => 1], ['migration' => Scenario::UPGRADE, 'batch' => 2]],
            default => [['migration' => Scenario::CREATE, 'batch' => 1], ['migration' => Scenario::UPGRADE, 'batch' => 1]],
        })
        ->and(Scenario::applicationMigrations($this->scratch.'/reader'))->toBe($applicationMigrations)
        ->and(Scenario::pending($this->scratch.'/reader'))->toBe([]);

    // The dataset itself: what P3 relies on finding in every dump.
    $seeded = $dump['storages'][$scenario === 'named' ? Restorer::NAMED['id'] : 'default'];
    $tables = $seeded['tables'];
    $audit = $tables['audit_log'];
    $grants = [...$tables['role_grants'], ...$tables['permission_grants']];
    $types = array_values(array_unique(array_column(array_filter($audit, static fn (array $row): bool => $row['panel'] === 'crm'), 'type')));
    sort($types);

    expect($types)->toBe(Dataset::JOURNALED)
        ->and(array_filter($audit, static fn (array $row): bool => $row['panel'] !== 'crm'))->toBe([])
        ->and(count(array_unique(array_column($audit, 'event_id'))))->toBe(count($audit))
        ->and(array_filter($audit, static fn (array $row): bool => array_diff(['event_id', 'type', 'occurred_at', 'panel', 'tenant', 'actor', 'correlation_id', 'state'], array_keys($row['payload'])) !== []))->toBe([])
        ->and(array_filter($tables['role_grants'], static fn (array $row): bool => $row[Dataset::APPLICATION_COLUMN] !== null))->not->toBe([])
        ->and(array_filter($grants, static fn (array $row): bool => $row['tenant_key'] === 'global'))->not->toBe([])
        ->and(array_filter($grants, static fn (array $row): bool => $row['tenant_key'] !== 'global' && $row['context_key'] === 'global'))->not->toBe([])
        ->and(array_filter($grants, static fn (array $row): bool => $row['context_type'] !== null))->not->toBe([])
        ->and(array_filter($grants, static fn (array $row): bool => $row['expires_at'] !== null && Carbon::parse($row['expires_at'], 'UTC')->lessThan(Carbon::parse(Dataset::PRUNE_CLOCK, 'UTC'))))->not->toBe([])
        ->and(array_filter($grants, static fn (array $row): bool => $row['expires_at'] !== null && Carbon::parse($row['expires_at'], 'UTC')->greaterThan(Carbon::parse(Dataset::PRUNE_CLOCK, 'UTC'))))->not->toBe([])
        ->and(array_filter($grants, static fn (array $row): bool => $row['expires_at'] === null))->not->toBe([])
        ->and(array_filter($grants, static fn (array $row): bool => $row['actor_type'] !== null && $row['actor_reason'] !== null))->not->toBe([])
        ->and(array_filter($grants, static fn (array $row): bool => $row['meta'] !== null))->not->toBe([])
        ->and(array_filter($grants, static fn (array $row): bool => $row['origin'] !== 'manual'))->not->toBe([])
        ->and(array_column($tables['permissions'], 'description'))->toBe(['Starts a campaign'])
        ->and(array_column($tables['panel_state'], 'panel'))->toBe(['crm', 'ops'])
        ->and(count($tables['subject_revisions']))->toBe($scenario === 'schema1' ? 0 : 5)
        ->and(array_column($tables['storage_state'], 'schema'))->toBe([['host_keys' => 'string', 'identity_codec' => 1, 'prefix' => $seeded['prefix'],
            'storage_id' => $scenario === 'named' ? Restorer::NAMED['id'] : 'default', 'version' => 2]]);
})->with(Restorer::SCENARIOS);
