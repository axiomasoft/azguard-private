<?php

declare(strict_types=1);

namespace AzGuard\Registry\Sources;

use AzGuard\Configuration\Config;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Registry\Contracts\GrantPriority;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * Grant source from DB roles via the role_permissions table.
 *
 * Covers roles without class_name (pure DB roles, not PHP classes).
 * Priority 90 (ClassRoleGrantSource = 100, DirectGrantSource = 80).
 *
 * Uses a single JOIN instead of N+1 queries for performance.
 */
final class DatabaseRoleGrantSource implements GrantSource
{
    #[Override]
    public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
    {
        $userId = $user->getAuthIdentifier();
        $userClass = $user->getMorphClass();

        $pivotTable = Config::modelHasRolesTable();
        $permTable = Config::rolePermissionsTable();

        $keys = DB::table($permTable)
            ->join($pivotTable, "{$pivotTable}.role_id", '=', "{$permTable}.role_id")
            ->where("{$pivotTable}.model_type", $userClass)
            ->where("{$pivotTable}.model_id", $userId)
            ->where("{$permTable}.panel_id", $panelId)
            ->pluck("{$permTable}.permission_key")
            ->all();

        if ($keys === []) {
            return PermissionSet::empty();
        }

        if (in_array(PermissionKey::WILDCARD, $keys, strict: true)) {
            return PermissionSet::wildcard();
        }

        return PermissionSet::fromKeys($keys);
    }

    #[Override]
    public function priority(): int
    {
        return GrantPriority::DatabaseRole->value;
    }
}
