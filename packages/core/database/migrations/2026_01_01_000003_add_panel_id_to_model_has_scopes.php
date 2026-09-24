<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds panel isolation to entity-scoped role assignments.
 *
 * Without panel_id, a scope assigned under panel A (e.g. an admin panel) is
 * indistinguishable from one assigned under panel B, and hasScopedPermission()
 * honours it regardless of which panel is currently being checked — breaching
 * the panel isolation boundary that DirectGrant and RolePermission already
 * enforce via their own panel_id column.
 *
 * A null panel_id is preserved as "any panel" for back-compat with rows
 * created before this migration (and callers that don't pass a panel).
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = config('az-guard.table_names');

        Schema::table($t['model_has_scopes'], function (Blueprint $table): void {
            $table->string('panel_id', 128)
                ->nullable()
                ->after('role_id')
                ->index();
        });
    }

    public function down(): void
    {
        $t = config('az-guard.table_names');

        Schema::table($t['model_has_scopes'], function (Blueprint $table): void {
            $table->dropIndex(['panel_id']);
        });

        Schema::table($t['model_has_scopes'], function (Blueprint $table): void {
            $table->dropColumn('panel_id');
        });
    }
};
