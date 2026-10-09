<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Storage;

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageRegistry;

final class DdlSnapshot
{
    /** @return array<string, mixed> */
    public static function table(Storage $storage, string $base): array
    {
        $connection = $storage->connection();
        $schema = $connection->getSchemaBuilder();
        $table = $storage->prefix().$base;
        $columns = $schema->getColumns($table);
        $indexes = $schema->getIndexes($table);
        usort($indexes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $constraints = match ($connection->getDriverName()) {
            'pgsql' => $connection->select("SELECT c.conname AS name, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE t.relname=? AND n.nspname=current_schema() AND c.contype='c' ORDER BY c.conname", [$table]),
            'sqlite' => $connection->select("SELECT name, sql AS definition FROM sqlite_master WHERE type='trigger' AND tbl_name=? ORDER BY name", [$table]),
            default => $connection->select('SELECT c.CONSTRAINT_NAME AS name, c.CHECK_CLAUSE AS definition FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=? ORDER BY c.CONSTRAINT_NAME', [$table]),
        };
        $constraints = array_map(static fn (object $row): array => (array) $row, $constraints);
        foreach (array_merge($indexes, $constraints) as $named) {
            expect(strlen($named['name']))->toBeLessThanOrEqual(63);
        }
        expect($schema->getForeignKeys($table))->toBe([]);

        return ['columns' => $columns, 'indexes' => $indexes, 'constraints' => $constraints, 'foreign_keys' => []];
    }

    public static function verify(): void
    {
        $registry = app(StorageRegistry::class);
        $storage = $registry->get('default');
        $schema = app(StorageSchema::class);
        $schema->drop('default');
        $schema->create('default');

        try {
            $snapshot = [];
            foreach (['permissions', 'role_grants', 'permission_grants', 'audit_log', 'panel_state', 'subject_revisions', 'storage_state'] as $base) {
                $snapshot['string'][$base] = self::table($storage, $base);
            }
            foreach (['uuid', 'bigint', 'ulid'] as $hostKeys) {
                $variant = new Storage($hostKeys, $storage->connection(), 'hk_'.$hostKeys.'_', $hostKeys);
                $registry->register($variant);
                $schema->drop($hostKeys);
                $schema->create($hostKeys);

                try {
                    $snapshot[$hostKeys]['role_grants'] = self::table($variant, 'role_grants');
                } finally {
                    $schema->drop($hostKeys);
                }
            }
            $driver = $storage->connection()->getDriverName();
            $path = __DIR__.'/ddl/'.$driver.'.json';
            $actual = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

            if (getenv('AZGUARD_UPDATE_SNAPSHOTS') === '1') {
                if (! is_dir(__DIR__.'/ddl')) {
                    mkdir(__DIR__.'/ddl');
                }
                file_put_contents($path, $actual);
            }
            expect(is_file($path))->toBeTrue('DDL snapshot missing: '.$path);
            expect($actual)->toBe(file_get_contents($path));
            // Record the environment separately: an engine patch version does not rewrite schema fixtures.
            $environment = match ($driver) {
                'pgsql' => $storage->connection()->selectOne('SELECT version() AS version'),
                'sqlite' => $storage->connection()->selectOne('SELECT sqlite_version() AS version'),
                default => $storage->connection()->selectOne('SELECT VERSION() AS version, @@innodb_page_size AS innodb_page_size, @@collation_database AS collation'),
            };
            file_put_contents(sys_get_temp_dir().'/azguard-ddl-environment-'.$driver.'.json', json_encode($environment, JSON_THROW_ON_ERROR));
        } finally {
            $schema->drop('default');
        }
    }
}
