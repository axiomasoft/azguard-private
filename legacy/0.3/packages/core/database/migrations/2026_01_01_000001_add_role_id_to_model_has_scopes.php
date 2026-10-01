<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $t = config('az-guard.table_names');

        Schema::table($t['model_has_scopes'], function (Blueprint $table) use ($t): void {
            $table->foreignId('role_id')
                ->nullable()
                ->after('scope_class')
                ->constrained($t['roles'])
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $t = config('az-guard.table_names');

        Schema::table($t['model_has_scopes'], function (Blueprint $table): void {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
