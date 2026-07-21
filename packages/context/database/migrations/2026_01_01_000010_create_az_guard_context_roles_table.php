<?php

declare(strict_types=1);

use AzGuard\Database\Schema\MorphColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Context roles table: rights scoped to context (workspace, project, etc.)
 *
 * A row = "user X in context (type=workspace, id=42) of the app panel
 *          has the app.posts.edit permission"
 */
return new class extends Migration
{
    public function up(): void
    {
        $binaryCollation = $this->mysqlBinaryCollation();

        Schema::create('az_guard_context_roles', function (Blueprint $table) use ($binaryCollation): void {
            $table->id();

            // User (polymorphic) — key type follows az-guard.column_names.morph_type
            MorphColumns::add($table, 'model', keyTypeCollation: $binaryCollation);

            // Context
            $this->keyString($table, 'context_type', 127, $binaryCollation); // 'workspace', 'project', ...
            $this->keyString($table, 'context_id', 64, $binaryCollation);    // string to support both UUID and int

            // Permission
            $this->keyString($table, 'panel_id', 128, $binaryCollation);
            $this->keyString($table, 'permission_key', 191, $binaryCollation);

            $table->timestamps();

            // Uniqueness: a user does not receive the same permission twice
            $table->unique(
                ['model_type', 'model_id', 'context_type', 'context_id', 'panel_id', 'permission_key'],
                'az_ctx_roles_unique',
            );

            // Indexes for fast lookups
            $table->index(['model_type', 'model_id', 'panel_id'], 'az_ctx_roles_user_panel');
            $table->index(['context_type', 'context_id'], 'az_ctx_roles_context');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('az_guard_context_roles');
    }

    private function mysqlBinaryCollation(): ?string
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'utf8mb4_bin'
            : null;
    }

    private function keyString(Blueprint $table, string $column, int $length, ?string $collation): void
    {
        $definition = $table->string($column, $length);

        if ($collation !== null) {
            $definition->collation($collation);
        }
    }
};
