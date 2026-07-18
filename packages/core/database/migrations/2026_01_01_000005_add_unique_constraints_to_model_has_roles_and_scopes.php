<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-16: model_has_roles carried no unique constraint at all — the same
 * (role, model) pair could be inserted any number of times. model_has_scopes
 * similarly allowed duplicate (model, scope entity, role, panel) rows. A new
 * migration (the applied base migration is not edited) adds both.
 *
 * P1.4 review hardening:
 * - Pre-existing duplicates are removed first (nothing forbade them before
 *   this migration), otherwise ADD UNIQUE aborts mid-migrate. Removing exact
 *   duplicates is safe and intentionally NOT restored by down().
 * - model_has_scopes' scope_entity_type/scope_entity_id/role_id/panel_id are
 *   nullable, and SQL UNIQUE treats NULLs as distinct — a plain column index
 *   leaves the DEFAULT assignScopedRole() shape (panel_id NULL, "any panel")
 *   unguarded. The nullable columns are COALESCE'd in the index expression so
 *   NULL participates in uniqueness.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = config('az-guard.table_names');

        $this->dedupeModelHasRoles($t['model_has_roles']);
        $this->dedupeModelHasScopes($t['model_has_scopes']);

        Schema::table($t['model_has_roles'], function (Blueprint $table): void {
            // All three columns are NOT NULL — a plain unique is NULL-safe here.
            $table->unique(['role_id', 'model_type', 'model_id'], 'model_has_roles_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Functional key parts (MySQL >= 8.0.13). The *_type columns are
            // capped at 191 chars (prefix / SUBSTRING) to stay under InnoDB's
            // 3072-byte key length limit (ROW_FORMAT=DYNAMIC, the default) —
            // a class FQCN colliding only past 191 chars is not a realistic
            // concern. Postgres/SQLite have no such limit.
            DB::statement(sprintf(
                'ALTER TABLE %s ADD UNIQUE INDEX model_has_scopes_unique '
                ."(model_type(191), model_id, (COALESCE(SUBSTRING(scope_entity_type, 1, 191), '')), "
                ."(COALESCE(scope_entity_id, 0)), (COALESCE(role_id, 0)), (COALESCE(panel_id, '')))",
                $t['model_has_scopes'],
            ));

            return;
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX model_has_scopes_unique ON %s '
            ."(model_type, model_id, COALESCE(scope_entity_type, ''), "
            ."COALESCE(scope_entity_id, 0), COALESCE(role_id, 0), COALESCE(panel_id, ''))",
            $t['model_has_scopes'],
        ));
    }

    public function down(): void
    {
        $t = config('az-guard.table_names');

        Schema::table($t['model_has_roles'], function (Blueprint $table): void {
            $table->dropUnique('model_has_roles_unique');
        });

        // The scopes index is created with raw SQL (expression index), so it
        // is dropped with raw SQL too: Blueprint::dropUnique() compiles to
        // DROP CONSTRAINT on Postgres, which does not match a plain index.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE %s DROP INDEX model_has_scopes_unique', $t['model_has_scopes']));

            return;
        }

        DB::statement('DROP INDEX model_has_scopes_unique');
    }

    /**
     * The table has no primary key, so "keep one per group" is a rebuild:
     * fetch the distinct rows, wipe the table, re-insert. Only runs when
     * duplicates actually exist.
     */
    private function dedupeModelHasRoles(string $table): void
    {
        $columns = ['role_id', 'model_type', 'model_id'];

        $hasDuplicates = DB::table($table)
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $hasDuplicates) {
            return;
        }

        $rows = DB::table($table)->select($columns)->distinct()->get();

        DB::table($table)->delete();

        foreach ($rows->chunk(500) as $chunk) {
            DB::table($table)->insert(
                $chunk->map(fn (object $row): array => (array) $row)->all(),
            );
        }
    }

    /**
     * Keep the lowest-id row of every duplicate group. GROUP BY treats NULLs
     * as equal, matching the COALESCE'd uniqueness added below.
     */
    private function dedupeModelHasScopes(string $table): void
    {
        $columns = ['model_type', 'model_id', 'scope_entity_type', 'scope_entity_id', 'role_id', 'panel_id'];

        $duplicateGroups = DB::table($table)
            ->selectRaw('MIN(id) as keep_id, '.implode(', ', $columns))
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $query = DB::table($table)->where('id', '!=', $group->keep_id);

            foreach ($columns as $column) {
                $query = $group->{$column} === null
                    ? $query->whereNull($column)
                    : $query->where($column, $group->{$column});
            }

            $query->delete();
        }
    }
};
