<?php

declare(strict_types=1);

use AzGuard\Database\Schema\AssignmentDeduplicator;
use AzGuard\Models\ModelHasScope;
use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return list<string>
 */
function azGuardCoreMigrationFiles(): array
{
    $files = glob(dirname(__DIR__, 2).'/packages/core/database/migrations/*.php');
    sort($files);

    return array_values($files);
}

function azGuardUniqueMigration(): object
{
    $migration = require dirname(__DIR__, 2)
        .'/packages/core/database/migrations/2026_01_01_000005_add_unique_constraints_to_model_has_roles_and_scopes.php';

    expect($migration)->toBeObject();

    return $migration;
}

function azGuardScopeRow(int|string $roleId, int|string $modelId, string $modelType, ?string $panelId, string $timestamp): array
{
    return [
        'model_type' => $modelType,
        'model_id' => $modelId,
        'scope_entity_type' => 'AzGuard\\Tests\\Stubs\\Project',
        'scope_entity_id' => 1,
        'scope_class' => null,
        'role_id' => $roleId,
        'panel_id' => $panelId,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
}

it('drops and recreates an empty fresh base schema in dependency order', function (): void {
    $tables = config('az-guard.table_names');
    $files = azGuardCoreMigrationFiles();

    try {
        foreach (array_reverse($files) as $file) {
            if (str_ends_with($file, '000000_create_az_guard_tables.php')) {
                expect(Schema::hasTable($tables['roles']))->toBeTrue()
                    ->and(Schema::hasTable($tables['model_has_roles']))->toBeTrue()
                    ->and(Schema::hasTable($tables['model_has_scopes']))->toBeTrue()
                    ->and(Schema::hasTable($tables['role_permissions']))->toBeFalse();
            }

            $migration = require $file;
            $migration->down();
        }

        expect(Schema::hasTable($tables['model_has_scopes']))->toBeFalse()
            ->and(Schema::hasTable($tables['model_has_roles']))->toBeFalse()
            ->and(Schema::hasTable($tables['roles']))->toBeFalse();

        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }

        expect(Schema::hasTable($tables['roles']))->toBeTrue()
            ->and(Schema::hasTable($tables['model_has_roles']))->toBeTrue()
            ->and(Schema::hasTable($tables['model_has_scopes']))->toBeTrue();
    } finally {
        if (! Schema::hasTable($tables['roles'])) {
            foreach ($files as $file) {
                $migration = require $file;
                $migration->up();
            }
        }
    }
});

it('does not treat 000004 down as successful while a null scope_class row exists', function (): void {
    $user = User::factory()->create();

    ModelHasScope::query()->create([
        'model_id' => $user->getKey(),
        'model_type' => $user->getMorphClass(),
        'scope_entity_id' => null,
        'scope_entity_type' => null,
        'role_id' => null,
        'panel_id' => null,
    ]);

    $migration = require dirname(__DIR__, 2)
        .'/packages/core/database/migrations/2026_01_01_000004_make_scope_class_nullable_on_model_has_scopes.php';

    $operation = DB::getDriverName() === 'pgsql'
        ? fn (): mixed => DB::transaction(fn () => $migration->down())
        : fn () => $migration->down();

    expect($operation)->toThrow(QueryException::class);
    expect(Schema::hasTable(config('az-guard.table_names.model_has_scopes')))->toBeTrue();
});

it('converges pre-000005 duplicate and distinct assignments without losing rows', function (): void {
    $migration = azGuardUniqueMigration();
    $migration->down();

    $tables = config('az-guard.table_names');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $role = Role::create(['name' => 'editor']);
    $otherRole = Role::create(['name' => 'viewer']);

    $duplicateRole = [
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
    ];
    $distinctRole = [
        'role_id' => $otherRole->getKey(),
        'model_type' => $other->getMorphClass(),
        'model_id' => $other->getKey(),
    ];

    DB::table($tables['model_has_roles'])->insert([
        $duplicateRole,
        $duplicateRole,
        $duplicateRole,
        $distinctRole,
    ]);

    $older = '2020-01-01 00:00:00';
    $newer = '2024-06-01 00:00:00';
    $keptScopeId = DB::table($tables['model_has_scopes'])->insertGetId(
        azGuardScopeRow($role->getKey(), $user->getKey(), $user->getMorphClass(), null, $older),
    );
    DB::table($tables['model_has_scopes'])->insert(
        azGuardScopeRow($role->getKey(), $user->getKey(), $user->getMorphClass(), null, $newer),
    );
    $distinctScopeId = DB::table($tables['model_has_scopes'])->insertGetId(
        azGuardScopeRow($role->getKey(), $user->getKey(), $user->getMorphClass(), 'app', $newer),
    );

    expect(fn () => $migration->up())->not->toThrow(Throwable::class);

    expect(DB::table($tables['model_has_roles'])->count())->toBe(2)
        ->and(DB::table($tables['model_has_roles'])->where($distinctRole)->exists())->toBeTrue()
        ->and(DB::table($tables['model_has_scopes'])->orderBy('id')->pluck('id')->all())->toBe([
            $keptScopeId,
            $distinctScopeId,
        ])
        ->and(DB::table($tables['model_has_scopes'])->where('id', $keptScopeId)->value('created_at'))
        ->toBe($older);
});

it('restores original role rows when insert fails after delete', function (): void {
    $migration = azGuardUniqueMigration();
    $tables = config('az-guard.table_names');

    try {
        $migration->down();

        $user = User::factory()->create();
        $role = Role::create(['name' => 'editor']);
        $row = [
            'role_id' => $role->getKey(),
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->getKey(),
        ];
        DB::table($tables['model_has_roles'])->insert([$row, $row, $row]);

        AssignmentDeduplicator::armFault('before-role-insert');

        expect(fn () => $migration->up())->toThrow(RuntimeException::class);
        expect(DB::table($tables['model_has_roles'])->count())->toBe(3)
            ->and(DB::table($tables['model_has_roles'])->where($row)->count())->toBe(3);

        AssignmentDeduplicator::resetFault();
        $migration->up();

        expect(DB::table($tables['model_has_roles'])->count())->toBe(1);
    } finally {
        AssignmentDeduplicator::resetFault();
    }
});

it('keeps a complete deduped set when index creation fails and converges on retry', function (): void {
    $migration = azGuardUniqueMigration();
    $tables = config('az-guard.table_names');

    try {
        $migration->down();

        $user = User::factory()->create();
        $role = Role::create(['name' => 'editor']);
        $roleRow = [
            'role_id' => $role->getKey(),
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->getKey(),
        ];
        DB::table($tables['model_has_roles'])->insert([$roleRow, $roleRow]);
        DB::table($tables['model_has_scopes'])->insert([
            azGuardScopeRow($role->getKey(), $user->getKey(), $user->getMorphClass(), 'app', '2024-01-01 00:00:00'),
            azGuardScopeRow($role->getKey(), $user->getKey(), $user->getMorphClass(), 'app', '2024-01-02 00:00:00'),
        ]);

        AssignmentDeduplicator::armFault('before-scope-index');

        expect(fn () => $migration->up())->toThrow(RuntimeException::class);
        expect(DB::table($tables['model_has_roles'])->count())->toBe(1)
            ->and(DB::table($tables['model_has_scopes'])->count())->toBe(1)
            ->and(Schema::hasIndex($tables['model_has_roles'], 'model_has_roles_unique'))->toBeTrue()
            ->and(Schema::hasIndex($tables['model_has_scopes'], 'model_has_scopes_identity_uq'))->toBeFalse();

        AssignmentDeduplicator::resetFault();
        $migration->up();

        expect(Schema::hasIndex($tables['model_has_scopes'], 'model_has_scopes_identity_uq'))->toBeTrue()
            ->and(DB::table($tables['model_has_roles'])->count())->toBe(1)
            ->and(DB::table($tables['model_has_scopes'])->count())->toBe(1);
    } finally {
        AssignmentDeduplicator::resetFault();
    }
});

it('records bounded dedupe memory and lock window without loading the pivot', function (): void {
    $migration = azGuardUniqueMigration();
    $tables = config('az-guard.table_names');
    $migration->down();

    $role = Role::create(['name' => 'bulk']);
    $roleRows = [];
    $scopeRows = [];

    for ($i = 1; $i <= 200; $i++) {
        $roleRow = [
            'role_id' => $role->getKey(),
            'model_type' => 'AzGuard\\Tests\\Stubs\\User',
            'model_id' => $i,
        ];
        $roleRows[] = $roleRow;
        $roleRows[] = $roleRow;
        $scopeRows[] = azGuardScopeRow($role->getKey(), $i, 'AzGuard\\Tests\\Stubs\\User', null, '2024-01-01 00:00:00');
        $scopeRows[] = azGuardScopeRow($role->getKey(), $i, 'AzGuard\\Tests\\Stubs\\User', null, '2024-02-01 00:00:00');
    }

    foreach (array_chunk($roleRows, 200) as $chunk) {
        DB::table($tables['model_has_roles'])->insert($chunk);
    }

    foreach (array_chunk($scopeRows, 100) as $chunk) {
        DB::table($tables['model_has_scopes'])->insert($chunk);
    }

    unset($roleRows, $scopeRows);

    $connection = Schema::getConnection();
    $deduper = new AssignmentDeduplicator;
    $before = memory_get_usage(true);
    $roles = $deduper->dedupeRoleAssignments($connection, $tables['model_has_roles']);
    $scopes = $deduper->dedupeScopeAssignments($connection, $tables['model_has_scopes']);
    $memoryDelta = memory_get_usage(true) - $before;

    file_put_contents('/tmp/p6.1-dedupe-evidence.json', json_encode([
        'role_source_rows' => $roles['source_rows'],
        'role_kept_rows' => $roles['kept_rows'],
        'role_lock_ns' => $roles['lock_ns'],
        'scope_source_rows' => $scopes['source_rows'],
        'scope_kept_rows' => $scopes['kept_rows'],
        'scope_lock_ns' => $scopes['lock_ns'],
        'memory_delta_bytes' => $memoryDelta,
    ], JSON_THROW_ON_ERROR));

    expect($roles['source_rows'])->toBe(400)
        ->and($roles['kept_rows'])->toBe(200)
        ->and($scopes['source_rows'])->toBe(400)
        ->and($scopes['kept_rows'])->toBe(200)
        ->and($roles['lock_ns'])->toBeGreaterThan(0)
        ->and($scopes['lock_ns'])->toBeGreaterThan(0);

    $migration->up();

    expect(DB::table($tables['model_has_roles'])->count())->toBe(200)
        ->and(DB::table($tables['model_has_scopes'])->count())->toBe(200);
});
