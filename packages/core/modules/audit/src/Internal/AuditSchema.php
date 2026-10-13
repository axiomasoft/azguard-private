<?php

declare(strict_types=1);

namespace AzGuard\Audit\Internal;

use AzGuard\Storage\Schema\IdentityColumns;
use AzGuard\Storage\StorageDescriptor;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

/**
 * @internal The audit journal table `{prefix}audit_log` of a storage: its columns, indexes and the tenant pair
 * constraint, with the identity columns of the core tables (`IdentityColumns`) so its rows compare with the stored
 * identities. The audit migrations create and drop it; the doctor compares an existing table with it.
 */
final class AuditSchema
{
    public const string TABLE = 'audit_log';

    /** Index names without the storage prefix. */
    public const array INDEXES = ['al_event', 'al_time', 'al_subject', 'al_corr'];

    /** The columns that hold a host key of the storage. */
    public const array HOST_KEY_COLUMNS = ['tenant_id', 'subject_id', 'actor_id'];

    public static function create(StorageDescriptor $storage): void
    {
        $prefix = $storage->prefix;
        self::connection($storage)->getSchemaBuilder()->create($prefix.self::TABLE, static function (Blueprint $table) use ($storage, $prefix): void {
            $table->bigIncrements('id');
            IdentityColumns::identifier($table, 'event_id', 26, $storage);
            IdentityColumns::identifier($table, 'type', 64, $storage);
            IdentityColumns::identifier($table, 'panel', 64, $storage);
            IdentityColumns::scope($table, 'tenant', $storage);
            IdentityColumns::identifier($table, 'subject_type', 128, $storage)->nullable();
            IdentityColumns::hostKey($table, 'subject_id', $storage)->nullable();
            IdentityColumns::identifier($table, 'actor_type', 128, $storage)->nullable();
            IdentityColumns::hostKey($table, 'actor_id', $storage)->nullable();
            $table->text('actor_reason')->nullable();
            IdentityColumns::identifier($table, 'correlation_id', 26, $storage);
            $table->json('payload');
            $table->dateTime('occurred_at');
            $table->unique('event_id', $prefix.'al_event');
            $table->index(['panel', 'occurred_at'], $prefix.'al_time');
            $table->index(['panel', 'tenant_key', 'subject_type', 'subject_id'], $prefix.'al_subject');
            $table->index('correlation_id', $prefix.'al_corr');
        });
        IdentityColumns::scopeConstraint($storage, self::TABLE, 'al', 'tenant');
    }

    public static function drop(StorageDescriptor $storage): void
    {
        self::connection($storage)->getSchemaBuilder()->dropIfExists($storage->prefix.self::TABLE);
    }

    public static function exists(StorageDescriptor $storage): bool
    {
        return self::connection($storage)->getSchemaBuilder()->hasTable($storage->prefix.self::TABLE);
    }

    /**
     * How the existing table of the storage differs from this schema: missing and extra columns, missing indexes and
     * host key columns whose type does not hold the host keys of the storage. Only reads the schema.
     *
     * @return list<string>
     */
    public static function differences(StorageDescriptor $storage): array
    {
        $schema = self::connection($storage)->getSchemaBuilder();
        $table = $storage->prefix.self::TABLE;
        $columns = [];

        foreach ($schema->getColumns($table) as $column) {
            $columns[strtolower($column['name'])] = strtolower($column['type_name']);
        }
        $expected = ['id', ...JournalRow::COLUMNS];
        $indexes = array_map(static fn (array $index): string => strtolower($index['name']), $schema->getIndexes($table));
        $differences = [];

        foreach (array_diff($expected, array_keys($columns)) as $column) {
            $differences[] = 'missing column '.$column;
        }

        foreach (array_diff(array_keys($columns), $expected) as $column) {
            $differences[] = 'extra column '.$column;
        }

        foreach (self::INDEXES as $index) {
            if (! in_array(strtolower($storage->prefix.$index), $indexes, true)) {
                $differences[] = 'missing index '.$storage->prefix.$index;
            }
        }

        foreach (self::HOST_KEY_COLUMNS as $column) {
            if (isset($columns[$column]) && str_contains($columns[$column], 'int') !== ($storage->hostKeys === 'bigint')) {
                $differences[] = 'column '.$column.' has type '.$columns[$column].' for '.$storage->hostKeys.' host keys';
            }
        }

        return $differences;
    }

    private static function connection(StorageDescriptor $storage): Connection
    {
        return app(DatabaseManager::class)->connection($storage->connection);
    }
}
