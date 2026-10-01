<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2.2: one seeded global permission-state revision row on the same
 * connection as the authorization tables. Official mutations bump it
 * in the same transaction when rows change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('az-guard.table_names.permission_state', 'az_guard_permission_state');

        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedTinyInteger('id')->primary();
            $blueprint->unsignedBigInteger('revision');
        });

        DB::table($table)->insert([
            'id' => 1,
            'revision' => 1,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('az-guard.table_names.permission_state', 'az_guard_permission_state'));
    }
};
