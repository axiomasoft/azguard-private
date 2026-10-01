<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes scope_class nullable to support logic-less scoped roles.
 *
 * A null scope_class indicates a logic-less role (no query-scope behavior).
 * This avoids the fragile anonymous-class sentinel pattern that was previously
 * used — storing an anonymous class name that cannot be reliably re-instantiated.
 *
 * Existing rows with anonymous-class names will remain unchanged; resolution
 * logic (HasScopedRoles::bootHasScopedRoles) already uses class_exists() guards
 * and treats non-existent classes as logic-less, so no data migration is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = config('az-guard.table_names');

        Schema::table($t['model_has_scopes'], function (Blueprint $table): void {
            $table->string('scope_class')->nullable()->change();
        });
    }

    /**
     * Reverting to NOT NULL is only safe while no logic-less role has been
     * assigned. On MySQL/PostgreSQL, if any row holds a null scope_class
     * (a logic-less role written under up()), this rollback fails with a
     * constraint violation — backfill or remove such rows before rolling back.
     */
    public function down(): void
    {
        $t = config('az-guard.table_names');

        Schema::table($t['model_has_scopes'], function (Blueprint $table): void {
            $table->string('scope_class')->nullable(false)->change();
        });
    }
};
