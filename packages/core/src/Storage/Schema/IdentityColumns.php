<?php

declare(strict_types=1);

namespace AzGuard\Storage\Schema;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Storage\StorageDescriptor;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * Identity columns of a table a module owns on an AzGuard storage, with the same types, lengths, byte semantics and
 * pair constraints as the core tables, so its rows compare with the stored identities of the core.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class IdentityColumns
{
    /** A byte-compared identifier column: binary on MySQL and MariaDB, collation `C` on PostgreSQL. */
    public static function identifier(Blueprint $table, string $column, int $length, StorageDescriptor $storage): ColumnDefinition
    {
        return HostKeyColumns::identifier($table, $column, $length, self::connection($storage)->getDriverName());
    }

    /** A column of host keys of the type the storage keeps (`string`, `bigint`, `uuid` or `ulid`). */
    public static function hostKey(Blueprint $table, string $column, StorageDescriptor $storage): ColumnDefinition
    {
        return HostKeyColumns::hostKey($table, $column, $storage->hostKeys, self::connection($storage)->getDriverName());
    }

    /** tenant_* or context_* triple: key (200), type (128, null), id (host key, null) */
    public static function scope(Blueprint $table, string $scope, StorageDescriptor $storage): void
    {
        self::identifier($table, $scope.'_key', 200, $storage);
        self::identifier($table, $scope.'_type', 128, $storage)->nullable();
        self::hostKey($table, $scope.'_id', $storage)->nullable();
    }

    /**
     * pair constraint (CHECK, or SQLite triggers) named {prefix}{short}_{scope}_pair
     *
     * The key is `global` exactly when type and id are null, and type and id are null together. Run it after the table
     * `$table` (a base name, without the prefix) exists.
     */
    public static function scopeConstraint(StorageDescriptor $storage, string $table, string $short, string $scope): void
    {
        $key = $scope.'_key';
        $type = $scope.'_type';
        $id = $scope.'_id';
        $name = $storage->prefix.$short.'_'.$scope.'_pair';
        $predicate = "(($key = 'global') = ($type IS NULL AND $id IS NULL)) AND (($type IS NULL) = ($id IS NULL))";
        $connection = self::connection($storage);
        $grammar = $connection->getQueryGrammar();
        $wrapped = $grammar->wrapTable($storage->prefix.$table);

        if ($connection->getDriverName() !== 'sqlite') {
            $connection->statement("ALTER TABLE $wrapped ADD CONSTRAINT {$grammar->wrap($name)} CHECK ($predicate)");

            return;
        }
        $columns = [$key, $type, $id];
        $newPredicate = str_replace($columns, array_map(static fn (string $column): string => 'NEW.'.$column, $columns), $predicate);

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE OF '.implode(', ', $columns)] as $suffix => $operation) {
            $trigger = $grammar->wrap($name.'_'.$suffix);
            $connection->statement("CREATE TRIGGER $trigger BEFORE $operation ON $wrapped WHEN NOT ($newPredicate) BEGIN SELECT RAISE(ABORT, 'azguard: {$short}_{$scope}_pair'); END");
        }
    }

    private static function connection(StorageDescriptor $storage): Connection
    {
        return app(DatabaseManager::class)->connection($storage->connection);
    }
}
