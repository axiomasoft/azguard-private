<?php

declare(strict_types=1);

use AzGuard\Database\Schema\AssignmentDeduplicator;
use AzGuard\Database\Schema\NullSafeUniqueIndex;
use AzGuard\Exceptions\IdentityIndexException;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrades a database that already recorded the sentinel 000005 index.
 *
 * Read-only preflight runs before any schema change: overlong panel ids and
 * unsupported engine capacity abort with the legacy index and rows intact.
 * A retry after an explicit data repair converges. A second successful run
 * is a no-op. This file is the deployed upgrade path; editing 000005 is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = config('az-guard.table_names');
        $connection = Schema::getConnection();
        $indexes = new NullSafeUniqueIndex;
        $components = NullSafeUniqueIndex::scopeComponents();

        $indexes->assertNoOverlongPanels($connection, $t['model_has_scopes']);
        $indexes->assertNoDuplicateClassNames($connection, $t['roles']);

        if ($this->converged($indexes, $connection, $t['model_has_scopes'], $t['roles'])) {
            return;
        }

        $indexes->assertCanCreate($connection, $t['model_has_scopes'], $components, projectPanel: true);

        $indexes->narrowPanel($connection, $t['model_has_scopes']);

        $deduper = new AssignmentDeduplicator;
        $deduper->dedupeRoleAssignments($connection, $t['model_has_roles']);
        $deduper->dedupeScopeAssignments($connection, $t['model_has_scopes']);

        $sql = $indexes->createSql($connection, $t['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX, $components);

        if (stripos($sql, 'substring') !== false) {
            throw new IdentityIndexException(
                IdentityIndexException::KEY_CAPACITY_UNSUPPORTED,
                'Exact identity DDL must not use SUBSTRING (verdict: '
                .IdentityIndexException::KEY_CAPACITY_UNSUPPORTED.').',
            );
        }

        $indexes->create($connection, $t['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX, $components);
        $indexes->drop($connection, $t['model_has_scopes'], NullSafeUniqueIndex::LEGACY_SCOPE_INDEX);
        $indexes->addClassNameUnique($connection, $t['roles']);
    }

    public function down(): void
    {
        $t = config('az-guard.table_names');
        $connection = Schema::getConnection();
        $indexes = new NullSafeUniqueIndex;

        $indexes->assertCanRestoreLegacyScopeIndex($connection, $t['model_has_scopes']);

        $indexes->dropClassNameUnique($connection, $t['roles']);
        $indexes->drop($connection, $t['model_has_scopes'], NullSafeUniqueIndex::SCOPE_INDEX);
        $indexes->restoreLegacyScopeIndex($connection, $t['model_has_scopes']);
    }

    private function converged(
        NullSafeUniqueIndex $indexes,
        Connection $connection,
        string $scopes,
        string $roles,
    ): bool {
        $width = $indexes->panelCharacterLength($connection, $scopes);
        $panelReady = $width === null || $width <= NullSafeUniqueIndex::PANEL_WIDTH;

        return $panelReady
            && $indexes->exists($connection, $scopes, NullSafeUniqueIndex::SCOPE_INDEX)
            && ! $indexes->exists($connection, $scopes, NullSafeUniqueIndex::LEGACY_SCOPE_INDEX)
            && $indexes->exists($connection, $roles, NullSafeUniqueIndex::CLASS_NAME_INDEX);
    }
};
