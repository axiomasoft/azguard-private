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
        Schema::create('az_guard_context_roles', function (Blueprint $table): void {
            $table->id();

            // User (polymorphic) — key type follows az-guard.column_names.morph_type
            MorphColumns::add($table, 'model');

            // Context
            $table->string('context_type');  // 'workspace', 'project', ...
            $table->string('context_id');    // string to support both UUID and int

            // Permission
            $table->string('panel_id');
            $table->string('permission_key');

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
};
