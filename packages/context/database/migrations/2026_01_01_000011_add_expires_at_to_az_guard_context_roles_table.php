<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TTL parity for context grants (unified grant grammar): a context grant
 * can now expire, exactly like a panel-wide direct grant. null = permanent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('permission_key');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
    }

    private function table(): string
    {
        return (string) config('az-guard-context.table_names.context_roles', 'az_guard_context_roles');
    }
};
