<?php

declare(strict_types=1);

namespace AzGuard\Sources\Database;

use AzGuard\Storage\Storage;

/**
 * @internal What the doctor reads about the tables of a storage: which are missing and whether the host key columns
 * have the type the storage declares. Only reads the schema.
 */
final class StorageHealth
{
    /** @var array<string, list<string>> table => columns that hold a host key */
    public const array HOST_KEY_COLUMNS = [
        'permissions' => ['tenant_id'],
        'role_grants' => ['tenant_id', 'subject_id', 'context_id', 'actor_id'],
        'permission_grants' => ['tenant_id', 'subject_id', 'context_id', 'actor_id'],
        'audit_log' => ['tenant_id', 'subject_id', 'actor_id'],
        'panel_state' => [],
        'subject_revisions' => ['subject_id'],
        'storage_state' => [],
    ];

    /**
     * @param  list<string>|null  $tables  base table names; all tables of the storage when null
     * @return list<string> the base names of the tables that do not exist
     */
    public static function missingTables(Storage $storage, ?array $tables = null): array
    {
        $schema = $storage->connection()->getSchemaBuilder();

        return array_values(array_filter(
            $tables ?? array_keys(self::HOST_KEY_COLUMNS),
            static fn (string $base): bool => ! $schema->hasTable($storage->prefix().$base),
        ));
    }

    /**
     * Host key columns whose type does not hold the host keys of the storage: a number column for string keys or a
     * text column for bigint keys. Missing tables and columns are not reported here.
     *
     * @return list<array{column: string, type: string}> `table.column` and the type the database reports
     */
    public static function hostKeyMismatches(Storage $storage): array
    {
        $schema = $storage->connection()->getSchemaBuilder();
        $numeric = $storage->hostKeys() === 'bigint';
        $mismatches = [];

        foreach (self::HOST_KEY_COLUMNS as $base => $columns) {
            if ($columns === [] || ! $schema->hasTable($storage->prefix().$base)) {
                continue;
            }

            foreach ($schema->getColumns($storage->prefix().$base) as $column) {
                $type = strtolower($column['type_name']);

                if (in_array($column['name'], $columns, true) && str_contains($type, 'int') !== $numeric) {
                    $mismatches[] = ['column' => $base.'.'.$column['name'], 'type' => $type];
                }
            }
        }

        return $mismatches;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string> the columns the table of the storage lacks
     */
    public static function missingColumns(Storage $storage, string $base, array $columns): array
    {
        $schema = $storage->connection()->getSchemaBuilder();
        $existing = array_map(static fn (array $column): string => strtolower($column['name']), $schema->getColumns($storage->prefix().$base));

        return array_values(array_filter($columns, static fn (string $column): bool => ! in_array(strtolower($column), $existing, true)));
    }
}
