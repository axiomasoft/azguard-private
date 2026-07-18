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
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = config('az-guard.table_names');

        Schema::table($t['model_has_roles'], function (Blueprint $table): void {
            $table->unique(['role_id', 'model_type', 'model_id'], 'model_has_roles_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // MySQL's InnoDB index key length limit (3072 bytes for
            // ROW_FORMAT=DYNAMIC, the default) is exceeded by three full-length
            // utf8mb4 varchar(255) columns in one composite key (~3094 bytes
            // here). Prefix the two morph *_type columns to 191 chars — the
            // legacy Laravel-safe length; a class FQCN colliding only past
            // 191 chars is not a realistic concern. Postgres/SQLite have no
            // such limit, so they get the full-length columns.
            DB::statement(sprintf(
                'ALTER TABLE %s ADD UNIQUE INDEX model_has_scopes_unique '
                .'(model_type(191), model_id, scope_entity_type(191), scope_entity_id, role_id, panel_id)',
                $t['model_has_scopes'],
            ));

            return;
        }

        Schema::table($t['model_has_scopes'], function (Blueprint $table): void {
            $table->unique(
                ['model_type', 'model_id', 'scope_entity_type', 'scope_entity_id', 'role_id', 'panel_id'],
                'model_has_scopes_unique',
            );
        });
    }

    public function down(): void
    {
        $t = config('az-guard.table_names');

        Schema::table($t['model_has_roles'], function (Blueprint $table): void {
            $table->dropUnique('model_has_roles_unique');
        });

        Schema::table($t['model_has_scopes'], function (Blueprint $table): void {
            $table->dropUnique('model_has_scopes_unique');
        });
    }
};
