<?php

declare(strict_types=1);

namespace AzGuard\Database\Schema;

use AzGuard\Configuration\Config;
use AzGuard\Exceptions\IdentityIndexException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Driver-aware exact unique index for nullable identity tuples.
 *
 * PostgreSQL 16+ uses NULLS NOT DISTINCT. SQLite and supported MySQL encode
 * each nullable component as an IS NULL marker plus the exact non-null value,
 * so NULL stays distinct from 0, empty string and the all-zero UUID.
 * Identifiers are validated segments and quoted by the active grammar.
 *
 * @internal
 */
final class NullSafeUniqueIndex
{
    public const string SCOPE_INDEX = 'model_has_scopes_identity_uq';

    public const string LEGACY_SCOPE_INDEX = 'model_has_scopes_unique';

    public const string ROLE_INDEX = 'model_has_roles_unique';

    public const string CLASS_NAME_INDEX = 'roles_class_name_unique';

    public const int PANEL_WIDTH = 128;

    public const int MYSQL_MIN_VERSION = 80013;

    public const int POSTGRES_MIN_MAJOR = 16;

    /**
     * @internal Injected MySQL profile for the fail-before-DDL path.
     *
     * @var array{version: string, page_size: int, engine: string, row_format: string}|null
     */
    private static ?array $mysqlProfileOverride = null;

    /**
     * @param  array{version: string, page_size: int, engine: string, row_format: string}|null  $profile
     *
     * @internal
     */
    public static function overrideMysqlProfile(?array $profile): void
    {
        self::$mysqlProfileOverride = $profile;
    }

    /**
     * @return list<array{column: string, nullable: bool, kind: 'string'|'integer'|'uuid'|'ulid', length: int|null}>
     */
    public static function scopeComponents(): array
    {
        $idKind = match (Config::morphType()) {
            'uuid' => 'uuid',
            'ulid' => 'ulid',
            default => 'integer',
        };

        return [
            ['column' => 'model_type', 'nullable' => false, 'kind' => 'string', 'length' => 255],
            ['column' => 'model_id', 'nullable' => false, 'kind' => $idKind, 'length' => null],
            ['column' => 'scope_entity_type', 'nullable' => true, 'kind' => 'string', 'length' => 255],
            ['column' => 'scope_entity_id', 'nullable' => true, 'kind' => $idKind, 'length' => null],
            ['column' => 'role_id', 'nullable' => true, 'kind' => 'integer', 'length' => null],
            ['column' => 'panel_id', 'nullable' => true, 'kind' => 'string', 'length' => self::PANEL_WIDTH],
        ];
    }

    /**
     * @return list<array{column: string, nullable: bool, kind: 'string'|'integer'|'uuid'|'ulid', length: int|null}>
     */
    public static function roleComponents(): array
    {
        $idKind = match (Config::morphType()) {
            'uuid' => 'uuid',
            'ulid' => 'ulid',
            default => 'integer',
        };

        return [
            ['column' => 'role_id', 'nullable' => false, 'kind' => 'integer', 'length' => null],
            ['column' => 'model_type', 'nullable' => false, 'kind' => 'string', 'length' => 255],
            ['column' => 'model_id', 'nullable' => false, 'kind' => $idKind, 'length' => null],
        ];
    }

    public static function assertIdentifier(string $name): void
    {
        $segments = explode('.', $name);

        if (count($segments) > 2) {
            self::rejectIdentifier($name);
        }

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                self::rejectIdentifier($name);
            }
        }
    }

    /**
     * @param  list<array{column: string, nullable: bool, kind: 'string'|'integer'|'uuid'|'ulid', length: int|null}>  $components
     */
    public function assertCanCreate(Connection $connection, string $table, array $components, bool $projectPanel = false): void
    {
        $this->guardNames($table, self::SCOPE_INDEX, $components);
        $driver = $this->driver($connection);

        if ($driver === 'pgsql') {
            $this->assertPostgres($connection);
        }

        if ($driver === 'mysql') {
            $bytes = $this->measuredKeyBytes($connection, $table, $components, $projectPanel);
            $this->assertMysqlProfile($this->mysqlProfile($connection, $table), $bytes);
        }
    }

    /**
     * @param  list<array{column: string, nullable: bool, kind: 'string'|'integer'|'uuid'|'ulid', length: int|null}>  $components
     */
    public function create(Connection $connection, string $table, string $index, array $components): void
    {
        $this->guardNames($table, $index, $components);

        if ($this->exists($connection, $table, $index)) {
            return;
        }

        $this->assertCanCreate($connection, $table, $components);

        $connection->statement($this->createSql($connection, $table, $index, $components));
    }

    public function drop(Connection $connection, string $table, string $index): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($index);

        if (! $this->exists($connection, $table, $index)) {
            return;
        }

        $quotedTable = $this->quoteTable($connection, $table);
        $quotedIndex = $this->quoteIdentifier($connection, $index);

        if ($this->driver($connection) === 'pgsql') {
            [$schema] = $this->split($table, $connection);
            $quotedIndex = $this->quoteIdentifier($connection, $schema.'.'.$index);
        }

        $sql = match ($this->driver($connection)) {
            'mysql' => "ALTER TABLE {$quotedTable} DROP INDEX {$quotedIndex}",
            'sqlite', 'pgsql' => "DROP INDEX {$quotedIndex}",
            default => throw $this->unsupportedDriver($this->driver($connection)),
        };

        $connection->statement($sql);
    }

    public function exists(Connection $connection, string $table, string $index): bool
    {
        self::assertIdentifier($table);
        self::assertIdentifier($index);
        [$schema, $bare] = $this->split($table, $connection);

        $row = match ($this->driver($connection)) {
            'sqlite' => $connection->selectOne(
                'select 1 as present from sqlite_master where type = ? and name = ? and tbl_name = ?',
                ['index', $index, $bare],
            ),
            'pgsql' => $connection->selectOne(
                'select 1 as present from pg_indexes where schemaname = ? and tablename = ? and indexname = ?',
                [$schema, $bare, $index],
            ),
            'mysql' => $connection->selectOne(
                'select 1 as present from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ?',
                [$schema, $bare, $index],
            ),
            default => throw $this->unsupportedDriver($this->driver($connection)),
        };

        return $row !== null;
    }

    public function definition(Connection $connection, string $table, string $index): string
    {
        self::assertIdentifier($table);
        self::assertIdentifier($index);
        [$schema, $bare] = $this->split($table, $connection);

        if ($this->driver($connection) === 'mysql') {
            $row = $connection->selectOne('show create table '.$this->quoteTable($connection, $table));

            return $row instanceof stdClass ? (string) ($row->{'Create Table'} ?? '') : '';
        }

        $row = match ($this->driver($connection)) {
            'sqlite' => $connection->selectOne(
                'select sql as definition from sqlite_master where type = ? and name = ? and tbl_name = ?',
                ['index', $index, $bare],
            ),
            'pgsql' => $connection->selectOne(
                'select indexdef as definition from pg_indexes where schemaname = ? and tablename = ? and indexname = ?',
                [$schema, $bare, $index],
            ),
            default => throw $this->unsupportedDriver($this->driver($connection)),
        };

        return $row instanceof stdClass ? (string) ($row->definition ?? '') : '';
    }

    /**
     * @param  list<array{column: string, nullable: bool, kind: 'string'|'integer'|'uuid'|'ulid', length: int|null}>  $components
     */
    public function declaredKeyBytes(array $components): int
    {
        $bytes = 0;

        foreach ($components as $component) {
            $bytes += $this->declaredValueBytes($component);
            $bytes += $component['nullable'] ? 1 : 0;
        }

        return $bytes;
    }

    /**
     * @param  list<array{column: string, nullable: bool, kind: 'string'|'integer'|'uuid'|'ulid', length: int|null}>  $components
     */
    public function measuredKeyBytes(Connection $connection, string $table, array $components, bool $projectPanel = false): int
    {
        if ($this->driver($connection) !== 'mysql') {
            return $this->declaredKeyBytes($components);
        }

        $bytes = 0;

        foreach ($components as $component) {
            $width = $this->mysqlColumnBytes($connection, $table, $component);

            if ($projectPanel && $component['column'] === 'panel_id') {
                $width = min($width, self::PANEL_WIDTH * 4);
            }

            $bytes += $width;
            $bytes += $component['nullable'] ? 1 : 0;
        }

        return $bytes;
    }

    /**
     * @param  array{version: string, page_size: int, engine: string, row_format: string}  $profile
     */
    public function assertMysqlProfile(array $profile, int $keyBytes): void
    {
        if (stripos($profile['version'], 'mariadb') !== false) {
            throw new IdentityIndexException(
                IdentityIndexException::MARIADB_UNSUPPORTED,
                'MariaDB cannot host this exact identity index (verdict: '
                .IdentityIndexException::MARIADB_UNSUPPORTED
                .'). Reading version() is required; the mysql driver name is not a support claim. '
                .'An isolated MariaDB DDL run is still required before any support claim.',
            );
        }

        if ($this->mysqlVersionNumber($profile['version']) < self::MYSQL_MIN_VERSION) {
            throw new IdentityIndexException(
                IdentityIndexException::MYSQL_VERSION_UNSUPPORTED,
                "MySQL {$profile['version']} is below 8.0.13 (verdict: "
                .IdentityIndexException::MYSQL_VERSION_UNSUPPORTED.').',
            );
        }

        if (strcasecmp($profile['engine'], 'InnoDB') !== 0) {
            throw new IdentityIndexException(
                IdentityIndexException::MYSQL_ENGINE_UNSUPPORTED,
                'Identity indexes require InnoDB (verdict: '
                .IdentityIndexException::MYSQL_ENGINE_UNSUPPORTED.').',
            );
        }

        $limit = match ($profile['page_size']) {
            16384 => 3072,
            8192 => 1536,
            4096 => 768,
            default => null,
        };

        if ($limit === null) {
            throw new IdentityIndexException(
                IdentityIndexException::MYSQL_PAGE_SIZE_UNSUPPORTED,
                "InnoDB page size {$profile['page_size']} is outside the declared 16 KiB profile (verdict: "
                .IdentityIndexException::MYSQL_PAGE_SIZE_UNSUPPORTED.'). 8 KiB and 4 KiB pages are unsupported.',
            );
        }

        if (! in_array(strtoupper($profile['row_format']), ['DYNAMIC', 'COMPRESSED'], true)) {
            throw new IdentityIndexException(
                IdentityIndexException::MYSQL_ROW_FORMAT_UNSUPPORTED,
                "Row format {$profile['row_format']} cannot hold the exact identity key (verdict: "
                .IdentityIndexException::MYSQL_ROW_FORMAT_UNSUPPORTED.'). DYNAMIC or COMPRESSED is required.',
            );
        }

        if ($keyBytes > $limit) {
            throw new IdentityIndexException(
                IdentityIndexException::KEY_CAPACITY_UNSUPPORTED,
                "Exact identity key needs {$keyBytes} bytes and this InnoDB page allows {$limit} (verdict: "
                .IdentityIndexException::KEY_CAPACITY_UNSUPPORTED.').',
            );
        }
    }

    public function assertNoOverlongPanels(Connection $connection, string $table): void
    {
        self::assertIdentifier($table);

        if (! Schema::connection($connection->getName())->hasTable($table)) {
            return;
        }

        $column = $this->quoteIdentifier($connection, 'panel_id');
        $length = $this->driver($connection) === 'sqlite' ? 'length' : 'char_length';
        $hit = $connection->table($table)
            ->whereNotNull('panel_id')
            ->whereRaw($length.'('.$column.') > '.self::PANEL_WIDTH)
            ->exists();

        if ($hit) {
            throw new IdentityIndexException(
                IdentityIndexException::PANEL_ID_OVERLENGTH,
                'model_has_scopes.panel_id contains a value longer than 128 characters (verdict: '
                .IdentityIndexException::PANEL_ID_OVERLENGTH
                .'). Shorten or rename those panel ids in an explicit data migration, then retry. AzGuard will not truncate them.',
            );
        }
    }

    public function assertNoDuplicateClassNames(Connection $connection, string $table): void
    {
        self::assertIdentifier($table);
        $quoted = $this->quoteTable($connection, $table);
        $hit = $connection->selectOne(
            "select 1 as present from {$quoted} where class_name is not null group by class_name having count(*) > 1",
        );

        if ($hit !== null) {
            throw new IdentityIndexException(
                IdentityIndexException::DUPLICATE_CLASS_NAME,
                'roles.class_name has duplicate non-null values (verdict: '
                .IdentityIndexException::DUPLICATE_CLASS_NAME
                .'). Resolve them explicitly, then retry. Multiple NULL class names stay allowed.',
            );
        }
    }

    public function panelCharacterLength(Connection $connection, string $table): ?int
    {
        self::assertIdentifier($table);
        $meta = $this->columnMeta($connection, $table, 'panel_id');

        return $meta['length'] ?? null;
    }

    public function narrowPanel(Connection $connection, string $table): void
    {
        self::assertIdentifier($table);
        $driver = $this->driver($connection);

        if ($driver === 'sqlite') {
            return;
        }

        $meta = $this->columnMeta($connection, $table, 'panel_id');

        if ($meta !== null && $meta['length'] !== null && $meta['length'] <= self::PANEL_WIDTH) {
            return;
        }

        $quotedTable = $this->quoteTable($connection, $table);
        $column = $this->quoteIdentifier($connection, 'panel_id');

        if ($driver === 'mysql') {
            $collate = ($meta['collation'] ?? '') !== '' ? ' COLLATE '.$meta['collation'] : '';
            $connection->statement(
                "ALTER TABLE {$quotedTable} MODIFY {$column} VARCHAR(".self::PANEL_WIDTH.") NULL{$collate}",
            );

            return;
        }

        $connection->statement(
            "ALTER TABLE {$quotedTable} ALTER COLUMN {$column} TYPE VARCHAR(".self::PANEL_WIDTH.')',
        );
    }

    public function addClassNameUnique(Connection $connection, string $table): void
    {
        self::assertIdentifier($table);

        if ($this->exists($connection, $table, self::CLASS_NAME_INDEX)) {
            return;
        }

        $connection->statement($this->createSql($connection, $table, self::CLASS_NAME_INDEX, [
            ['column' => 'class_name', 'nullable' => false, 'kind' => 'string', 'length' => 255],
        ]));
    }

    public function dropClassNameUnique(Connection $connection, string $table): void
    {
        $this->drop($connection, $table, self::CLASS_NAME_INDEX);
    }

    /**
     * Historical sentinel index. Only the upgrade migration's down() restores it.
     */
    public function restoreLegacyScopeIndex(Connection $connection, string $table): void
    {
        self::assertIdentifier($table);

        if ($this->exists($connection, $table, self::LEGACY_SCOPE_INDEX)) {
            return;
        }

        $quotedTable = $this->quoteTable($connection, $table);
        $quotedIndex = $this->quoteIdentifier($connection, self::LEGACY_SCOPE_INDEX);
        $fallback = match (Config::morphType()) {
            'int' => '0',
            'uuid' => "'00000000-0000-0000-0000-000000000000'",
            default => "''",
        };

        if ($this->driver($connection) === 'mysql') {
            $connection->statement(
                "ALTER TABLE {$quotedTable} ADD UNIQUE INDEX {$quotedIndex} "
                ."(model_type(191), model_id, (COALESCE(SUBSTRING(scope_entity_type, 1, 191), '')), "
                ."(COALESCE(scope_entity_id, {$fallback})), (COALESCE(role_id, 0)), (COALESCE(panel_id, '')))",
            );

            return;
        }

        $connection->statement(
            "CREATE UNIQUE INDEX {$quotedIndex} ON {$quotedTable} "
            ."(model_type, model_id, COALESCE(scope_entity_type, ''), "
            ."COALESCE(scope_entity_id, {$fallback}), COALESCE(role_id, 0), COALESCE(panel_id, ''))",
        );
    }

    /**
     * Downgrading the exact index is unsafe when rows added since the upgrade
     * collide under the historical sentinel or MySQL prefix identity. Check
     * before any index is dropped; MySQL DDL cannot be rolled back.
     */
    public function assertCanRestoreLegacyScopeIndex(Connection $connection, string $table): void
    {
        self::assertIdentifier($table);

        if ($this->exists($connection, $table, self::LEGACY_SCOPE_INDEX)) {
            return;
        }

        $quotedTable = $this->quoteTable($connection, $table);
        $column = fn (string $name): string => $this->quoteIdentifier($connection, $name);
        $fallback = match (Config::morphType()) {
            'int' => '0',
            'uuid' => "'00000000-0000-0000-0000-000000000000'",
            default => "''",
        };
        $mysql = $this->driver($connection) === 'mysql';
        $modelType = $column('model_type');
        $scopeType = $column('scope_entity_type');
        $identity = [
            $mysql ? "SUBSTRING({$modelType}, 1, 191)" : $modelType,
            $column('model_id'),
            $mysql ? "COALESCE(SUBSTRING({$scopeType}, 1, 191), '')" : "COALESCE({$scopeType}, '')",
            "COALESCE({$column('scope_entity_id')}, {$fallback})",
            "COALESCE({$column('role_id')}, 0)",
            "COALESCE({$column('panel_id')}, '')",
        ];
        $groupBy = implode(', ', $identity);
        $collision = $connection->selectOne(
            "SELECT 1 AS present FROM {$quotedTable} GROUP BY {$groupBy} HAVING COUNT(*) > 1 LIMIT 1",
        );

        if ($collision !== null) {
            throw new IdentityIndexException(
                IdentityIndexException::LEGACY_IDENTITY_COLLISION,
                'Exact identity rows collide under the historical sentinel/prefix index (verdict: '
                .IdentityIndexException::LEGACY_IDENTITY_COLLISION
                .'). Downgrade was refused before dropping any index. Resolve the conflicting rows explicitly or keep the exact migration.',
            );
        }
    }

    /**
     * @param  list<array{column: string, nullable: bool, kind: 'string'|'integer'|'uuid'|'ulid', length: int|null}>  $components
     */
    public function createSql(Connection $connection, string $table, string $index, array $components): string
    {
        $quotedTable = $this->quoteTable($connection, $table);
        $quotedIndex = $this->quoteIdentifier($connection, $index);
        $driver = $this->driver($connection);
        $nullable = false;

        foreach ($components as $component) {
            if ($component['nullable']) {
                $nullable = true;

                break;
            }
        }

        if (! $nullable || $driver === 'pgsql') {
            $columns = implode(', ', array_map(
                fn (array $component): string => $this->quoteIdentifier($connection, $component['column']),
                $components,
            ));
            $nulls = $nullable ? ' NULLS NOT DISTINCT' : '';

            return match ($driver) {
                'mysql' => "ALTER TABLE {$quotedTable} ADD UNIQUE INDEX {$quotedIndex} ({$columns})",
                default => "CREATE UNIQUE INDEX {$quotedIndex} ON {$quotedTable} ({$columns}){$nulls}",
            };
        }

        $parts = [];

        foreach ($components as $component) {
            $column = $this->quoteIdentifier($connection, $component['column']);

            if (! $component['nullable']) {
                $parts[] = $column;

                continue;
            }

            $fallback = match ($component['kind']) {
                'integer' => '0',
                'uuid' => "'00000000-0000-0000-0000-000000000000'",
                default => "''",
            };
            $marker = "({$column} IS NULL)";
            $value = "COALESCE({$column}, {$fallback})";

            if ($driver === 'mysql') {
                $parts[] = "({$marker})";
                $parts[] = "({$value})";
            } else {
                $parts[] = $marker;
                $parts[] = $value;
            }
        }

        $list = implode(', ', $parts);

        return $driver === 'mysql'
            ? "ALTER TABLE {$quotedTable} ADD UNIQUE INDEX {$quotedIndex} ({$list})"
            : "CREATE UNIQUE INDEX {$quotedIndex} ON {$quotedTable} ({$list})";
    }

    /**
     * @param  list<array{column: string, nullable: bool, kind: string, length: int|null}>  $components
     */
    private function guardNames(string $table, string $index, array $components): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($index);

        foreach ($components as $component) {
            self::assertIdentifier($component['column']);
        }
    }

    /**
     * @param  array{column: string, nullable: bool, kind: string, length: int|null}  $component
     */
    private function declaredValueBytes(array $component): int
    {
        return match ($component['kind']) {
            'integer' => 8,
            'uuid' => 36 * 4,
            'ulid' => 26 * 4,
            default => ($component['length'] ?? 255) * 4,
        };
    }

    /**
     * @param  array{column: string, nullable: bool, kind: string, length: int|null}  $component
     */
    private function mysqlColumnBytes(Connection $connection, string $table, array $component): int
    {
        $meta = $this->columnMeta($connection, $table, $component['column']);

        if ($meta === null) {
            return $this->declaredValueBytes($component);
        }

        if (in_array($meta['data_type'], ['bigint', 'int', 'integer', 'smallint', 'mediumint', 'tinyint'], true)) {
            return 8;
        }

        $chars = $meta['length'] ?? $component['length'] ?? 255;
        $perChar = match ($meta['charset']) {
            'utf8mb4' => 4,
            'utf8', 'utf8mb3' => 3,
            'latin1', 'ascii' => 1,
            default => 4,
        };

        return $chars * $perChar;
    }

    /**
     * @return array{length: int|null, charset: string, collation: string, data_type: string}|null
     */
    private function columnMeta(Connection $connection, string $table, string $column): ?array
    {
        if (! in_array($this->driver($connection), ['mysql', 'pgsql'], true)) {
            return null;
        }

        [$schema, $bare] = $this->split($table, $connection);
        $row = $connection->selectOne(
            'select character_maximum_length as length, character_set_name as charset, collation_name as collation, data_type '
            .'from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
            [$schema, $bare, $column],
        );

        if (! $row instanceof stdClass) {
            return null;
        }

        $length = $this->rowValue($row, 'length');

        return [
            'length' => $length !== '' ? (int) $length : null,
            'charset' => $this->rowValue($row, 'charset'),
            'collation' => $this->rowValue($row, 'collation'),
            'data_type' => strtolower($this->rowValue($row, 'data_type')),
        ];
    }

    private function assertPostgres(Connection $connection): void
    {
        $row = $connection->selectOne('show server_version');
        $version = $row instanceof stdClass ? $this->rowValue($row, 'server_version') : '';
        $major = (int) explode('.', $version)[0];

        if ($major < self::POSTGRES_MIN_MAJOR) {
            throw new IdentityIndexException(
                IdentityIndexException::POSTGRES_VERSION_UNSUPPORTED,
                "PostgreSQL {$version} is below 16 (verdict: "
                .IdentityIndexException::POSTGRES_VERSION_UNSUPPORTED
                .'). Exact identity indexes need NULLS NOT DISTINCT.',
            );
        }
    }

    /**
     * @return array{version: string, page_size: int, engine: string, row_format: string}
     */
    private function mysqlProfile(Connection $connection, string $table): array
    {
        if (self::$mysqlProfileOverride !== null) {
            return self::$mysqlProfileOverride;
        }

        $version = $connection->selectOne('select version() as version');
        $page = $connection->selectOne('select @@innodb_page_size as page_size');
        [$schema, $bare] = $this->split($table, $connection);
        $tableRow = $connection->selectOne(
            'select engine, row_format from information_schema.tables where table_schema = ? and table_name = ?',
            [$schema, $bare],
        );

        return [
            'version' => $version instanceof stdClass ? $this->rowValue($version, 'version') : '',
            'page_size' => $page instanceof stdClass ? (int) $this->rowValue($page, 'page_size') : 0,
            'engine' => $tableRow instanceof stdClass ? $this->rowValue($tableRow, 'engine') : '',
            'row_format' => $tableRow instanceof stdClass ? $this->rowValue($tableRow, 'row_format') : '',
        ];
    }

    private function rowValue(object $row, string $name): string
    {
        $values = array_change_key_case(get_object_vars($row), CASE_LOWER);

        return (string) ($values[strtolower($name)] ?? '');
    }

    private function mysqlVersionNumber(string $version): int
    {
        if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $match) !== 1) {
            return 0;
        }

        return ((int) $match[1] * 10000) + ((int) $match[2] * 100) + (int) $match[3];
    }

    private function quoteTable(Connection $connection, string $table): string
    {
        self::assertIdentifier($table);

        return $connection->getQueryGrammar()->wrapTable($table);
    }

    private function quoteIdentifier(Connection $connection, string $name): string
    {
        self::assertIdentifier($name);

        return $connection->getQueryGrammar()->wrap($name);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $table, Connection $connection): array
    {
        self::assertIdentifier($table);
        $segments = explode('.', $table);

        if (count($segments) === 2) {
            return [$segments[0], $segments[1]];
        }

        $schema = match ($this->driver($connection)) {
            'pgsql' => (string) ($connection->scalar('select current_schema()') ?? 'public'),
            'mysql' => (string) $connection->getDatabaseName(),
            default => '',
        };

        return [$schema, $table];
    }

    private function driver(Connection $connection): string
    {
        $driver = $connection->getDriverName();

        if ($driver === 'mariadb') {
            throw new IdentityIndexException(
                IdentityIndexException::MARIADB_UNSUPPORTED,
                'MariaDB cannot host this exact identity index (verdict: '
                .IdentityIndexException::MARIADB_UNSUPPORTED
                .'). An isolated MariaDB DDL run is required before any support claim.',
            );
        }

        return $driver;
    }

    private function unsupportedDriver(string $driver): IdentityIndexException
    {
        return new IdentityIndexException(
            IdentityIndexException::MYSQL_ENGINE_UNSUPPORTED,
            "Driver [{$driver}] has no exact identity index path.",
        );
    }

    private static function rejectIdentifier(string $name): never
    {
        throw new IdentityIndexException(
            IdentityIndexException::INVALID_IDENTIFIER,
            "AzGuard refused schema identifier [{$name}] before DDL (verdict: "
            .IdentityIndexException::INVALID_IDENTIFIER
            .'). Use one or two dot-separated identifier segments, never an alias, comment or expression.',
        );
    }
}
