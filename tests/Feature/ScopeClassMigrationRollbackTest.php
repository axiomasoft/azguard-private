<?php

declare(strict_types=1);

use AzGuard\Models\ModelHasScope;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\QueryException;

/**
 * T5 (2026.07.17-AZGUARD-TAILS P2.3). The down() docblock of migration
 * 2026_01_01_000004_make_scope_class_nullable_on_model_has_scopes.php claims
 * rolling back to NOT NULL fails on MySQL/PostgreSQL when a null scope_class
 * row exists, but names no rollback test. This EXPERIMENTALLY establishes the
 * actual behavior on this project's SQLite test DB — the docblock's claim is
 * NOT assumed to transfer.
 *
 * Finding (see Completion Notes in phases/P2.md for the full write-up): on
 * SQLite, ->nullable(false)->change() is implemented by doctrine/dbal via a
 * "recreate table + copy rows" strategy (visible in the exception's SQL:
 * `insert into "__temp__model_has_scopes" (...) select ... from
 * "model_has_scopes"`); the copy step itself enforces the new NOT NULL
 * constraint and throws a QueryException with SQLite's own integrity-violation
 * wording ("NOT NULL constraint failed"), not a MySQL/PostgreSQL-style
 * constraint error message. The DOCUMENTED CONCLUSION (down() is unsafe with
 * existing null scope_class rows) holds on SQLite too — no docblock
 * discrepancy found, so the docblock is NOT amended.
 */
describe('T5 — migration 000004 down() rollback with a null scope_class row', function (): void {
    it('experimentally throws a QueryException on SQLite when down() runs against a null scope_class row', function (): void {
        $user = User::factory()->create();

        // A logic-less scoped-role row: scope_class stored as null. up() already
        // ran (RefreshDatabase), so the column is nullable at this point.
        // scope_class is guarded (C-11, not mass-assignable) — null is also
        // the column's own default, so omitting it from create() is enough.
        ModelHasScope::query()->create([
            'model_id' => $user->getKey(),
            'model_type' => $user->getMorphClass(),
            'scope_entity_id' => null,
            'scope_entity_type' => null,
            'role_id' => null,
            'panel_id' => null,
        ]);

        expect(ModelHasScope::query()->whereNull('scope_class')->exists())->toBeTrue();

        $migrationPath = dirname(__DIR__, 2)
            .'/packages/core/database/migrations/2026_01_01_000004_make_scope_class_nullable_on_model_has_scopes.php';

        /** @var object{down: callable} $migration */
        $migration = require $migrationPath;

        expect(fn () => $migration->down())
            ->toThrow(QueryException::class);
    });

    it('does not throw down() when no null scope_class row exists', function (): void {
        $user = User::factory()->create();

        // scope_class is guarded (C-11, not mass-assignable) — set it via a
        // direct property assignment after create() instead.
        $scope = ModelHasScope::query()->create([
            'model_id' => $user->getKey(),
            'model_type' => $user->getMorphClass(),
            'scope_entity_id' => null,
            'scope_entity_type' => null,
            'role_id' => null,
            'panel_id' => null,
        ]);
        $scope->scope_class = 'AzGuard\\Tests\\Stubs\\Roles\\ProjectEditorRole';
        $scope->save();

        $migrationPath = dirname(__DIR__, 2)
            .'/packages/core/database/migrations/2026_01_01_000004_make_scope_class_nullable_on_model_has_scopes.php';

        /** @var object{down: callable} $migration */
        $migration = require $migrationPath;

        expect(fn () => $migration->down())->not->toThrow(Throwable::class);
    });
});
