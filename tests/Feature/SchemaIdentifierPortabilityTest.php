<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Database\Schema\MorphColumns;
use AzGuard\Database\Schema\NullSafeUniqueIndex;
use AzGuard\Exceptions\IdentityIndexException;
use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function identityIndexes(): NullSafeUniqueIndex
{
    return new NullSafeUniqueIndex;
}

function expectIdentityConflict(Closure $operation): void
{
    $operation = DB::getDriverName() === 'pgsql'
        ? fn (): mixed => DB::transaction($operation)
        : $operation;

    expect($operation)->toThrow(QueryException::class);
}

function hardenMigration(): object
{
    $migration = require dirname(__DIR__, 2)
        .'/packages/core/database/migrations/2026_01_01_000006_harden_az_guard_identity_indexes.php';

    expect($migration)->toBeObject();

    return $migration;
}

function contextRolesMigration(): object
{
    $migration = require dirname(__DIR__, 2)
        .'/packages/context/database/migrations/2026_01_01_000010_create_az_guard_context_roles_table.php';

    expect($migration)->toBeObject();

    return $migration;
}

/**
 * @param  array<string, string>  $tables
 */
function withScratchTables(array $tables, Closure $callback): void
{
    $original = config('az-guard.table_names');

    try {
        foreach ($tables as $key => $name) {
            config()->set("az-guard.table_names.{$key}", $name);
        }

        $indexes = identityIndexes();
        $indexes->drop(DB::connection(), (string) $original['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX);
        $indexes->drop(DB::connection(), (string) $original['model_has_scopes'], NullSafeUniqueIndex::LEGACY_SCOPE_INDEX);
        $indexes->drop(DB::connection(), (string) $original['roles'], NullSafeUniqueIndex::CLASS_NAME_INDEX);

        $callback();
    } finally {
        config()->set('az-guard.table_names', $original);
        Schema::dropIfExists($tables['model_has_scopes']);
        Schema::dropIfExists($tables['model_has_roles']);
        Schema::dropIfExists($tables['roles']);
        NullSafeUniqueIndex::overrideMysqlProfile(null);
    }
}

/**
 * @return array<string, string>
 */
function legacyIdentityTables(string $suffix): array
{
    return [
        'roles' => 'azg_legacy_roles_'.$suffix,
        'model_has_roles' => 'azg_legacy_roles_pivot_'.$suffix,
        'model_has_scopes' => 'azg_legacy_scopes_'.$suffix,
    ];
}

function createLegacyIdentityTables(array $tables, ?string $panelValue = null): void
{
    Schema::create($tables['roles'], function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('class_name')->nullable();
        $table->integer('level')->default(0);
        $table->timestamps();
    });
    Schema::create($tables['model_has_roles'], function (Blueprint $table): void {
        $table->unsignedBigInteger('role_id');
        MorphColumns::add($table, 'model');
    });
    Schema::create($tables['model_has_scopes'], function (Blueprint $table): void {
        $table->id();
        MorphColumns::add($table, 'model');
        MorphColumns::add($table, 'scope_entity', nullable: true);
        $table->string('scope_class')->nullable();
        $table->unsignedBigInteger('role_id')->nullable();
        $table->string('panel_id', 255)->nullable();
        $table->timestamps();
    });

    $user = User::factory()->create();
    DB::table($tables['model_has_scopes'])->insert([
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'scope_entity_type' => 'AzGuard\\Tests\\Stubs\\Project',
        'scope_entity_id' => 1,
        'scope_class' => null,
        'role_id' => null,
        'panel_id' => $panelValue,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    identityIndexes()->restoreLegacyScopeIndex(DB::connection(), $tables['model_has_scopes']);
}

function emittedScopeIndex(string $table): string
{
    $quoted = DB::getDriverName() === 'mysql' ? '`'.$table.'`' : '"'.$table.'"';

    return str_replace(
        $quoted,
        '"scopes"',
        identityIndexes()->createSql(
            DB::connection(),
            $table,
            NullSafeUniqueIndex::SCOPE_INDEX,
            NullSafeUniqueIndex::scopeComponents(),
        ),
    );
}

it('budgets the declared uuid identity key at 2852 bytes', function (): void {
    $original = config('az-guard.column_names.morph_type');

    try {
        config()->set('az-guard.column_names.morph_type', 'uuid');

        expect(identityIndexes()->declaredKeyBytes(NullSafeUniqueIndex::scopeComponents()))->toBe(2852);

        config()->set('az-guard.column_names.morph_type', 'ulid');

        expect(identityIndexes()->declaredKeyBytes(NullSafeUniqueIndex::scopeComponents()))->toBe(2772)
            ->and(identityIndexes()->declaredKeyBytes(NullSafeUniqueIndex::scopeComponents()))->toBeLessThan(3072);

        config()->set('az-guard.column_names.morph_type', 'int');

        expect(identityIndexes()->declaredKeyBytes(NullSafeUniqueIndex::scopeComponents()))->toBe(2580)
            ->and(identityIndexes()->declaredKeyBytes(NullSafeUniqueIndex::scopeComponents()))->toBeGreaterThan(1536);
    } finally {
        config()->set('az-guard.column_names.morph_type', $original);
    }
});

it('rejects sql-like identifiers before ddl', function (): void {
    foreach (['roles;drop', 'roles -- comment', 'roles alias', 'a b', 'public.roles/**/'] as $name) {
        expect(fn () => NullSafeUniqueIndex::assertIdentifier($name))
            ->toThrow(IdentityIndexException::class, IdentityIndexException::INVALID_IDENTIFIER);
    }

    expect(fn () => NullSafeUniqueIndex::assertIdentifier('Model_Has_Scopes'))->not->toThrow(IdentityIndexException::class)
        ->and(fn () => NullSafeUniqueIndex::assertIdentifier('custom.model_has_scopes'))->not->toThrow(IdentityIndexException::class);
});

it('quotes a reserved mixed-case table and builds the exact index', function (): void {
    $table = 'AzGuard_Order';
    Schema::dropIfExists($table);

    try {
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('role_id');
            $blueprint->string('model_type');
            $blueprint->unsignedBigInteger('model_id');
        });

        $indexes = identityIndexes();
        $sql = $indexes->createSql(DB::connection(), $table, 'azg_order_uq', NullSafeUniqueIndex::roleComponents());
        $driver = DB::getDriverName();
        $quoted = $driver === 'mysql' ? '`AzGuard_Order`' : '"AzGuard_Order"';

        expect($sql)->toContain($quoted);

        $indexes->create(DB::connection(), $table, 'azg_order_uq', NullSafeUniqueIndex::roleComponents());

        expect($indexes->exists(DB::connection(), $table, 'azg_order_uq'))->toBeTrue();
    } finally {
        Schema::dropIfExists($table);
    }
});

it('drops a schema-qualified PostgreSQL index outside search_path', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('PostgreSQL schema qualification is engine-specific.');
    }

    $schema = 'azg_portability';
    $table = $schema.'.AzGuard_Order';
    DB::statement('CREATE SCHEMA "azg_portability"');

    try {
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('role_id');
            $blueprint->string('model_type');
            $blueprint->unsignedBigInteger('model_id');
        });

        $indexes = identityIndexes();
        $indexes->create(DB::connection(), $table, 'azg_schema_order_uq', NullSafeUniqueIndex::roleComponents());
        expect($indexes->exists(DB::connection(), $table, 'azg_schema_order_uq'))->toBeTrue();

        $indexes->drop(DB::connection(), $table, 'azg_schema_order_uq');
        expect($indexes->exists(DB::connection(), $table, 'azg_schema_order_uq'))->toBeFalse();
    } finally {
        Schema::dropIfExists($table);
        DB::statement('DROP SCHEMA "azg_portability"');
    }
});

it('collides only on the full nullable tuple and keeps zero empty and long suffixes distinct', function (): void {
    $table = Config::modelHasScopesTable();
    $user = User::factory()->create();
    $prefix = str_repeat('a', 191);
    $base = [
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'scope_class' => null,
        'role_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table($table)->insert($base + [
        'scope_entity_type' => null,
        'scope_entity_id' => null,
        'panel_id' => null,
    ]);

    expectIdentityConflict(fn () => DB::table($table)->insert($base + [
        'scope_entity_type' => null,
        'scope_entity_id' => null,
        'panel_id' => null,
    ]));

    DB::table($table)->insert($base + [
        'scope_entity_type' => '',
        'scope_entity_id' => 0,
        'panel_id' => '',
    ]);

    DB::table($table)->insert($base + [
        'scope_entity_type' => $prefix.'X',
        'scope_entity_id' => 1,
        'panel_id' => 'app',
    ]);
    DB::table($table)->insert($base + [
        'scope_entity_type' => $prefix.'Y',
        'scope_entity_id' => 1,
        'panel_id' => 'app',
    ]);

    $definition = strtolower(identityIndexes()->definition(DB::connection(), $table, NullSafeUniqueIndex::SCOPE_INDEX));

    expect($definition)->not->toContain('substring')
        ->and(DB::getDriverName() === 'pgsql' ? $definition : 'is null')->toContain(DB::getDriverName() === 'pgsql' ? 'nulls not distinct' : 'is null');
});

it('allows many null class names and rejects a duplicate non-null class name', function (): void {
    Role::query()->create(['name' => 'db-only-a']);
    Role::query()->create(['name' => 'db-only-b']);

    $first = Role::query()->create(['name' => 'code-a']);
    $first->class_name = '';
    $first->save();

    $second = Role::query()->create(['name' => 'code-b']);
    $second->class_name = 'AzGuard\\Tests\\EditorRole';
    $second->save();

    $duplicate = Role::query()->create(['name' => 'code-c']);
    $duplicate->class_name = 'AzGuard\\Tests\\EditorRole';

    expectIdentityConflict(fn () => $duplicate->save());
    expect(identityIndexes()->exists(DB::connection(), Config::rolesTable(), NullSafeUniqueIndex::CLASS_NAME_INDEX))->toBeTrue();
});

it('upgrades a legacy scope index to the same exact index and repeats as a no-op', function (): void {
    $tables = legacyIdentityTables('parity');
    $fresh = emittedScopeIndex(Config::modelHasScopesTable());

    withScratchTables($tables, function () use ($tables, $fresh): void {
        createLegacyIdentityTables($tables);
        $migration = hardenMigration();
        $migration->up();

        expect(emittedScopeIndex($tables['model_has_scopes']))->toBe($fresh)
            ->and(identityIndexes()->exists(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX))->toBeTrue()
            ->and(identityIndexes()->exists(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::LEGACY_SCOPE_INDEX))->toBeFalse()
            ->and(strtolower(identityIndexes()->definition(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX)))->not->toContain('substring');

        $migration->up();
        $migration->down();
        $migration->up();

        expect(identityIndexes()->exists(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX))->toBeTrue()
            ->and(identityIndexes()->exists(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::LEGACY_SCOPE_INDEX))->toBeFalse();
    });
});

it('refuses downgrade before dropping exact indexes when legacy identities collide', function (): void {
    $tables = legacyIdentityTables('downgrade');

    withScratchTables($tables, function () use ($tables): void {
        createLegacyIdentityTables($tables);
        $migration = hardenMigration();
        $migration->up();

        $base = [
            'model_type' => 'AzGuard\\Tests\\Stubs\\User',
            'model_id' => 923,
            'scope_entity_type' => null,
            'scope_class' => null,
            'role_id' => null,
            'panel_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table($tables['model_has_scopes'])->insert($base + ['scope_entity_id' => null]);
        DB::table($tables['model_has_scopes'])->insert($base + ['scope_entity_id' => 0]);

        try {
            $migration->down();
            test()->fail('Downgrade should reject rows that collide under the legacy index.');
        } catch (IdentityIndexException $exception) {
            expect($exception->verdict)->toBe(IdentityIndexException::LEGACY_IDENTITY_COLLISION);
        }

        expect(identityIndexes()->exists(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX))->toBeTrue()
            ->and(identityIndexes()->exists(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::LEGACY_SCOPE_INDEX))->toBeFalse()
            ->and(identityIndexes()->exists(DB::connection(), $tables['roles'], NullSafeUniqueIndex::CLASS_NAME_INDEX))->toBeTrue();
    });
});

it('rejects an overlong panel id before changing the legacy index', function (): void {
    $tables = legacyIdentityTables('long');

    withScratchTables($tables, function () use ($tables): void {
        createLegacyIdentityTables($tables, str_repeat('p', 129));
        $before = DB::table($tables['model_has_scopes'])->count();

        expect(fn () => hardenMigration()->up())
            ->toThrow(IdentityIndexException::class, IdentityIndexException::PANEL_ID_OVERLENGTH);

        expect(DB::table($tables['model_has_scopes'])->count())->toBe($before)
            ->and(identityIndexes()->exists(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::LEGACY_SCOPE_INDEX))->toBeTrue()
            ->and(identityIndexes()->exists(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX))->toBeFalse()
            ->and((string) DB::table($tables['model_has_scopes'])->value('panel_id'))->toHaveLength(129);
    });
});

it('rejects an 8 kib page and a compact row format before ddl', function (): void {
    $tables = legacyIdentityTables('page');

    withScratchTables($tables, function () use ($tables): void {
        if (DB::getDriverName() !== 'mysql') {
            expect(identityIndexes()->declaredKeyBytes(NullSafeUniqueIndex::scopeComponents()))->toBeGreaterThan(1536);

            return;
        }

        createLegacyIdentityTables($tables);
        NullSafeUniqueIndex::overrideMysqlProfile([
            'version' => '8.0.36',
            'page_size' => 8192,
            'engine' => 'InnoDB',
            'row_format' => 'Dynamic',
        ]);

        expect(fn () => hardenMigration()->up())
            ->toThrow(IdentityIndexException::class, IdentityIndexException::KEY_CAPACITY_UNSUPPORTED);

        NullSafeUniqueIndex::overrideMysqlProfile([
            'version' => '8.0.36',
            'page_size' => 4096,
            'engine' => 'InnoDB',
            'row_format' => 'Dynamic',
        ]);

        expect(fn () => hardenMigration()->up())
            ->toThrow(IdentityIndexException::class, IdentityIndexException::KEY_CAPACITY_UNSUPPORTED);

        expect(identityIndexes()->exists(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::LEGACY_SCOPE_INDEX))->toBeTrue()
            ->and(identityIndexes()->exists(DB::connection(), $tables['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX))->toBeFalse();

        NullSafeUniqueIndex::overrideMysqlProfile([
            'version' => '8.0.36',
            'page_size' => 16384,
            'engine' => 'InnoDB',
            'row_format' => 'Compact',
        ]);

        expect(fn () => hardenMigration()->up())
            ->toThrow(IdentityIndexException::class, IdentityIndexException::MYSQL_ROW_FORMAT_UNSUPPORTED);

        NullSafeUniqueIndex::overrideMysqlProfile([
            'version' => '11.4.2-MariaDB',
            'page_size' => 16384,
            'engine' => 'InnoDB',
            'row_format' => 'Dynamic',
        ]);

        expect(fn () => hardenMigration()->up())
            ->toThrow(IdentityIndexException::class, IdentityIndexException::MARIADB_UNSUPPORTED);
    });
});

it('uses one configured context table for create and drop', function (): void {
    $custom = 'tenant_context_roles';
    $original = config('az-guard-context.table_names.context_roles');
    Schema::dropIfExists($custom);

    try {
        config()->set('az-guard-context.table_names.context_roles', 'tenant context;drop');

        expect(fn () => contextRolesMigration()->up())
            ->toThrow(IdentityIndexException::class, IdentityIndexException::INVALID_IDENTIFIER);
        expect(Schema::hasTable($custom))->toBeFalse();

        config()->set('az-guard-context.table_names.context_roles', $custom);
        $migration = contextRolesMigration();
        $migration->up();

        expect(Schema::hasTable($custom))->toBeTrue();

        $migration->down();

        expect(Schema::hasTable($custom))->toBeFalse();
    } finally {
        Schema::dropIfExists($custom);
        config()->set('az-guard-context.table_names.context_roles', $original);
    }
});

it('keeps a null morph id distinct from the all-zero uuid', function (): void {
    $table = 'azg_uuid_scope_identity';
    $originalMorph = config('az-guard.column_names.morph_type');
    Schema::dropIfExists($table);

    try {
        config()->set('az-guard.column_names.morph_type', 'uuid');
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            MorphColumns::add($blueprint, 'model');
            MorphColumns::add($blueprint, 'scope_entity', nullable: true);
            $blueprint->unsignedBigInteger('role_id')->nullable();
            $blueprint->string('panel_id', 128)->nullable();
        });

        $indexes = identityIndexes();
        $bytes = $indexes->measuredKeyBytes(DB::connection(), $table, NullSafeUniqueIndex::scopeComponents());

        expect($bytes)->toBeLessThanOrEqual(3072);

        $indexes->create(DB::connection(), $table, 'azg_uuid_identity_uq', NullSafeUniqueIndex::scopeComponents());

        $row = [
            'model_type' => 'AzGuard\\Tests\\Stubs\\User',
            'model_id' => '11111111-1111-1111-1111-111111111111',
            'scope_entity_type' => 'AzGuard\\Tests\\Stubs\\Project',
            'role_id' => null,
            'panel_id' => null,
        ];
        DB::table($table)->insert($row + ['scope_entity_id' => null]);
        DB::table($table)->insert($row + ['scope_entity_id' => '00000000-0000-0000-0000-000000000000']);

        expectIdentityConflict(fn () => DB::table($table)->insert($row + ['scope_entity_id' => null]));
    } finally {
        Schema::dropIfExists($table);
        config()->set('az-guard.column_names.morph_type', $originalMorph);
    }
});

it('executes the declared ULID scope index with exact null identity', function (): void {
    $table = 'azg_ulid_scope_identity';
    $originalMorph = config('az-guard.column_names.morph_type');
    Schema::dropIfExists($table);

    try {
        config()->set('az-guard.column_names.morph_type', 'ulid');
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            MorphColumns::add($blueprint, 'model');
            MorphColumns::add($blueprint, 'scope_entity', nullable: true);
            $blueprint->unsignedBigInteger('role_id')->nullable();
            $blueprint->string('panel_id', 128)->nullable();
        });

        $indexes = identityIndexes();
        expect($indexes->measuredKeyBytes(DB::connection(), $table, NullSafeUniqueIndex::scopeComponents()))
            ->toBeLessThanOrEqual(3072);
        $indexes->create(DB::connection(), $table, 'azg_ulid_identity_uq', NullSafeUniqueIndex::scopeComponents());

        $row = [
            'model_type' => 'AzGuard\\Tests\\Stubs\\User',
            'model_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'scope_entity_type' => 'AzGuard\\Tests\\Stubs\\Project',
            'role_id' => null,
            'panel_id' => null,
        ];
        DB::table($table)->insert($row + ['scope_entity_id' => null]);
        DB::table($table)->insert($row + ['scope_entity_id' => '00000000000000000000000000']);

        expectIdentityConflict(fn () => DB::table($table)->insert($row + ['scope_entity_id' => null]));
    } finally {
        Schema::dropIfExists($table);
        config()->set('az-guard.column_names.morph_type', $originalMorph);
    }
});
