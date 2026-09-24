<?php

declare(strict_types=1);

use AzGuard\Database\Schema\AssignmentDeduplicator;
use AzGuard\Database\Schema\NullSafeUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds exact unique identity to role and scope assignments.
 *
 * P6.1 dedupe stays DB-side and transactional. Run it in a maintenance window
 * with no concurrent writers. An insert failure restores the original role
 * rows; an index failure may leave a complete deduped set and must be retried.
 * Editing this file does not upgrade a database that already recorded it.
 * That upgrade is 2026_01_01_000006_harden_az_guard_identity_indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = config('az-guard.table_names');
        $deduper = new AssignmentDeduplicator;
        $connection = Schema::getConnection();
        $indexes = new NullSafeUniqueIndex;

        $deduper->dedupeRoleAssignments($connection, $t['model_has_roles']);
        $deduper->dedupeScopeAssignments($connection, $t['model_has_scopes']);

        $indexes->create(
            $connection,
            $t['model_has_roles'],
            NullSafeUniqueIndex::ROLE_INDEX,
            NullSafeUniqueIndex::roleComponents(),
        );
        AssignmentDeduplicator::consumeFault('before-scope-index');
        $indexes->create(
            $connection,
            $t['model_has_scopes'],
            NullSafeUniqueIndex::SCOPE_INDEX,
            NullSafeUniqueIndex::scopeComponents(),
        );
    }

    public function down(): void
    {
        $t = config('az-guard.table_names');
        $connection = Schema::getConnection();
        $indexes = new NullSafeUniqueIndex;
        $isMysql = $connection->getDriverName() === 'mysql';

        if ($isMysql) {
            Schema::table($t['model_has_roles'], function (Blueprint $table): void {
                $table->dropForeign(['role_id']);
            });
        }

        $indexes->drop($connection, $t['model_has_roles'], NullSafeUniqueIndex::ROLE_INDEX);

        if ($isMysql) {
            Schema::table($t['model_has_roles'], function (Blueprint $table) use ($t): void {
                $table->foreign('role_id')->references('id')->on($t['roles'])->cascadeOnDelete();
            });
        }

        $indexes->drop($connection, $t['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX);
        $indexes->drop($connection, $t['model_has_scopes'], NullSafeUniqueIndex::LEGACY_SCOPE_INDEX);
    }
};
