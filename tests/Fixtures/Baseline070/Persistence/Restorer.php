<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Baseline070\Persistence;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Storage\DdlSnapshot;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Restores a populated 0.7.0 database from its dump (`<driver>/<scenario>.json`) without 0.7.0 production code: the
 * tables come from `Legacy070Schema`, the rows from raw inserts, the migrations table from the recorded rows (I16).
 *
 * `rows()` is the one value normalisation of the dumps, used by the writer and the reader alike:
 * - JSON columns (`meta`, `payload`, `schema`) are decoded, object keys sorted (MySQL reorders stored JSON);
 * - every other non-null value is a string (drivers return integers as int or string);
 * - when the writer captures the generated database (`$wallClock`), `panel_state.updated_at` is replaced by
 *   `Dataset::CLOCK`: 0.7.0 writes it from the wall clock (`gmdate()`), not from the application clock, so it is the
 *   only value a run cannot fix. A restored database is read as stored, so a changed timestamp is a difference.
 */
final class Restorer
{
    public const string BASELINE = '09612160c98ca6133c894a8f90f5653b9dac6b8f';

    public const array SCENARIOS = ['default', 'named', 'published', 'schema1'];

    /** The named storage of scenario `named`. */
    public const array NAMED = ['id' => 'ledger', 'prefix' => 'lgr_'];

    private const array JSON_COLUMNS = ['meta', 'payload', 'schema'];

    /** Primary key order of each table. */
    private const array ORDER = ['permissions' => ['id'], 'role_grants' => ['id'], 'permission_grants' => ['id'], 'audit_log' => ['id'],
        'panel_state' => ['panel'], 'subject_revisions' => ['panel', 'subject_type', 'subject_id'], 'storage_state' => ['id']];

    public static function path(string $driver, string $scenario): string
    {
        return __DIR__.'/'.$driver.'/'.$scenario.'.json';
    }

    /** @return array<string, mixed> the dump; a missing dump fails the run, it never skips */
    public static function load(string $driver, string $scenario): array
    {
        $path = self::path($driver, $scenario);

        if (! is_file($path)) {
            throw new RuntimeException('Missing populated 0.7.0 dump '.$path.'; generate it from the 0.7.0 code with AZGUARD_BASELINE_WRITE=1.');
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Registers the storages of a scenario in the configuration, as the application that owns the dump configures them.
     *
     * @param  list<string>  $storages
     */
    public static function configure(array $storages): void
    {
        if (in_array(self::NAMED['id'], $storages, true)) {
            config()->set('azguard.storages.'.self::NAMED['id'], ['connection' => null, 'table_prefix' => self::NAMED['prefix'], 'host_keys' => null]);
        }
        foreach ([AzGuardConfig::class, StorageRegistry::class, StorageSchema::class] as $service) {
            app()->forgetInstance($service);
        }
    }

    /** @param array<string, mixed> $dump */
    public static function restore(array $dump, Connection $connection): void
    {
        foreach ($dump['storages'] as $id => $storage) {
            $legacy = new Legacy070Schema($connection, $id, $storage['prefix'], $storage['host_keys']);

            if ($dump['header']['scenario'] === 'schema1' && $id === 'default') {
                $legacy->createSchema1();
                $legacy->upgrade();
            } else {
                $legacy->create();
            }

            if ($storage['application_columns'] !== []) {
                Dataset::addApplicationColumns($connection, $storage['prefix']);
            }
            foreach ($storage['tables'] as $base => $rows) {
                $table = $storage['prefix'].$base;
                $connection->table($table)->delete();

                foreach ($rows as $row) {
                    $connection->table($table)->insert(array_map(
                        static fn (mixed $value): mixed => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value, $row));
                }

                if ($connection->getDriverName() === 'pgsql' && self::ORDER[$base] === ['id'] && $base !== 'storage_state' && $rows !== []) {
                    $connection->statement("SELECT setval(pg_get_serial_sequence('$table', 'id'), (SELECT MAX(id) FROM \"$table\"))");
                }
            }
        }

        $repository = app('migration.repository');
        $repository->setSource($connection->getName());

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }
        foreach ($dump['migrations'] as $migration) {
            $repository->log($migration['migration'], $migration['batch']);
        }
    }

    /** `DdlSnapshot::table()` of a storage table, with `uniqueConstraints()`. */
    public static function ddl(Storage $storage, string $base): array
    {
        return self::uniqueConstraints(DdlSnapshot::table($storage, $base));
    }

    /**
     * On MariaDB the check-constraint query of `DdlSnapshot` joins by constraint name only, so it repeats the
     * same-named `json_valid` constraint (`meta`, `payload`) once per table of the database that has it, in the shared
     * fixture `tests/Fixtures/Storage/ddl/mariadb.json` as well; the repeated entries are dropped on both sides.
     *
     * @param  array<string, mixed>  $ddl
     * @return array<string, mixed>
     */
    public static function uniqueConstraints(array $ddl): array
    {
        $ddl['constraints'] = array_values(array_unique($ddl['constraints'], SORT_REGULAR));

        return $ddl;
    }

    /**
     * @param  bool  $wallClock  the rows come from the database 0.7.0 generated (writer), not from a restored dump
     * @return list<array<string, mixed>> the rows of a table in primary key order, normalised
     */
    public static function rows(Connection $connection, string $prefix, string $base, bool $wallClock = false): array
    {
        $query = $connection->table($prefix.$base);
        foreach (self::ORDER[$base] as $column) {
            $query->orderBy($column);
        }

        return array_map(static function (object $row) use ($base, $wallClock): array {
            $values = [];

            foreach ((array) $row as $column => $value) {
                $values[$column] = match (true) {
                    $value === null => null,
                    $wallClock && $base === 'panel_state' && $column === 'updated_at' => Dataset::CLOCK,
                    in_array($column, self::JSON_COLUMNS, true) => self::sorted(json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR)),
                    default => (string) $value,
                };
            }

            return $values;
        }, $query->get()->all());
    }

    /** @return array<string, string|int> the AzGuard rows of the migrations table, in the order they ran */
    public static function migrations(Connection $connection): array
    {
        return array_map(static fn (object $row): array => ['migration' => (string) $row->migration, 'batch' => (int) $row->batch],
            $connection->table('migrations')->where('migration', 'like', '%azguard%')->orderBy('id')->get()->all());
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }
}
