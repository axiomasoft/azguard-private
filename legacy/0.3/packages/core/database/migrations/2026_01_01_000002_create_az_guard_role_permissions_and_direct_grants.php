<?php

use AzGuard\Database\Schema\MorphColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tables for Phase 3 of the grants system:
 *
 * az_guard_role_permissions — permissions assigned to a DB role (not PHP classes)
 * az_guard_direct_grants   — permissions granted directly to a user (without a role), with a TTL
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = config('az-guard.table_names');
        $binaryCollation = $this->mysqlBinaryCollation();

        // DB role permissions (roles without a class_name, or custom DB roles)
        Schema::create($t['role_permissions'] ?? 'az_guard_role_permissions', function (Blueprint $table) use ($binaryCollation, $t) {
            $table->id();
            $table->foreignId('role_id')
                ->constrained($t['roles'])
                ->cascadeOnDelete();
            $this->keyString($table, 'permission_key', 255, $binaryCollation); // resolved key: "app.documents.view"
            $this->keyString($table, 'panel_id', 128, $binaryCollation);       // "app", "admin"
            $table->timestamps();

            $table->unique(['role_id', 'permission_key', 'panel_id'], 'az_role_perm_unique');
            $table->index(['panel_id', 'permission_key'], 'az_role_perm_lookup');
        });

        // Direct grants to a user (without a role)
        Schema::create($t['direct_grants'] ?? 'az_guard_direct_grants', function (Blueprint $table) use ($binaryCollation) {
            $table->id();
            MorphColumns::add($table, 'grantable', keyTypeCollation: $binaryCollation); // the user (User or any model)
            $this->keyString($table, 'permission_key', 255, $binaryCollation); // resolved key
            $this->keyString($table, 'panel_id', 128, $binaryCollation);       // "app"
            $table->timestamp('expires_at')->nullable(); // null = never expires
            $table->timestamps();

            $table->unique(
                ['grantable_type', 'grantable_id', 'permission_key', 'panel_id'],
                'az_direct_grant_unique',
            );
            // expires_at trails the lookup keys so DirectGrantSource's active()
            // range scan stays within this index for a user+panel slice.
            $table->index(['grantable_type', 'grantable_id', 'panel_id', 'expires_at'], 'az_direct_grant_lookup');
        });
    }

    public function down(): void
    {
        $t = config('az-guard.table_names');

        Schema::dropIfExists($t['direct_grants'] ?? 'az_guard_direct_grants');
        Schema::dropIfExists($t['role_permissions'] ?? 'az_guard_role_permissions');
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
