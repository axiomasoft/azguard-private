<?php

declare(strict_types=1);

use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * C-16: model_has_roles carried no unique constraint at all — the same
 * (role, model) pair could be inserted any number of times. model_has_scopes
 * similarly allowed duplicate (model, scope entity, role, panel) rows.
 */
function migrationPath(): string
{
    return dirname(__DIR__, 2)
        .'/packages/core/database/migrations/2026_01_01_000005_add_unique_constraints_to_model_has_roles_and_scopes.php';
}

it('rejects a duplicate (role, model) row in model_has_roles', function () {
    $t = config('az-guard.table_names');
    $user = User::factory()->create();
    $role = Role::create(['name' => 'editor']);

    $row = [
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
    ];

    DB::table($t['model_has_roles'])->insert($row);

    expect(fn () => DB::table($t['model_has_roles'])->insert($row))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate (model, scope entity, role, panel) row in model_has_scopes', function () {
    $t = config('az-guard.table_names');
    $user = User::factory()->create();
    $role = Role::create(['name' => 'editor']);

    $row = [
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'scope_entity_type' => 'AzGuard\\Tests\\Stubs\\Project',
        'scope_entity_id' => 1,
        'scope_class' => 'AzGuard\\Tests\\Stubs\\Roles\\ProjectEditorRole',
        'role_id' => $role->getKey(),
        'panel_id' => 'app',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table($t['model_has_scopes'])->insert($row);

    expect(fn () => DB::table($t['model_has_scopes'])->insert($row))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate NULL-panel scope row — the default assignScopedRole() shape (P1.4 review)', function () {
    $t = config('az-guard.table_names');
    $user = User::factory()->create();
    $role = Role::create(['name' => 'editor']);

    // panel_id NULL ("any panel") is what assignScopedRole() persists by
    // default; SQL UNIQUE treats NULLs as distinct, so a plain column index
    // would not guard this shape at all — the COALESCE'd expression index must.
    $row = [
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'scope_entity_type' => 'AzGuard\\Tests\\Stubs\\Project',
        'scope_entity_id' => 1,
        'scope_class' => null,
        'role_id' => $role->getKey(),
        'panel_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table($t['model_has_scopes'])->insert($row);

    expect(fn () => DB::table($t['model_has_scopes'])->insert($row))
        ->toThrow(QueryException::class);
});

it('deduplicates pre-existing rows before adding the constraints (P1.4 review)', function () {
    /** @var object{up: callable, down: callable} $migration */
    $migration = require migrationPath();

    $migration->down();

    $t = config('az-guard.table_names');
    $user = User::factory()->create();
    $role = Role::create(['name' => 'editor']);

    $roleRow = [
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
    ];
    $scopeRow = [
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'scope_entity_type' => 'AzGuard\\Tests\\Stubs\\Project',
        'scope_entity_id' => 1,
        'scope_class' => null,
        'role_id' => $role->getKey(),
        'panel_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // Without the constraints nothing forbids duplicates — the exact state a
    // real installation upgrades from. up() must not abort on them.
    DB::table($t['model_has_roles'])->insert([$roleRow, $roleRow, $roleRow]);
    DB::table($t['model_has_scopes'])->insert([$scopeRow, $scopeRow]);

    expect(fn () => $migration->up())->not->toThrow(Throwable::class);

    expect(DB::table($t['model_has_roles'])->count())->toBe(1)
        ->and(DB::table($t['model_has_scopes'])->count())->toBe(1);
});

it('is reversible — down() then up() runs without error and the constraint is restored', function () {
    /** @var object{up: callable, down: callable} $migration */
    $migration = require migrationPath();

    expect(fn () => $migration->down())->not->toThrow(Throwable::class);
    expect(fn () => $migration->up())->not->toThrow(Throwable::class);

    $t = config('az-guard.table_names');
    $user = User::factory()->create();
    $role = Role::create(['name' => 'editor']);

    $row = [
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
    ];

    DB::table($t['model_has_roles'])->insert($row);

    expect(fn () => DB::table($t['model_has_roles'])->insert($row))
        ->toThrow(QueryException::class);
});
