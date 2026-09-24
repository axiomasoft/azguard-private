<?php

declare(strict_types=1);

namespace AzGuard\Registry\Sources;

use AzGuard\Configuration\Config;
use AzGuard\Models\RolePermission;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Registry\Contracts\GrantPriority;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

/**
 * Grant source from DB roles via the role_permissions table.
 *
 * Covers roles without class_name (pure DB roles, not PHP classes).
 * Priority 90 (ClassRoleGrantSource = 100, DirectGrantSource = 80).
 *
 * Reads assigned role IDs once, then resolves scoped permission rows with a
 * live-role existence check. The query count is fixed per authorization check.
 */
final class DatabaseRoleGrantSource implements GrantSource
{
    #[Override]
    public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
    {
        Config::assertAuthorizationConnectionsAligned();

        $userId = $user->getAuthIdentifier();
        $userClass = $user->getMorphClass();

        $pivotTable = Config::modelHasRolesTable();
        $permissionModel = Config::rolePermissionModel();
        /** @var RolePermission $permissionPrototype */
        $permissionPrototype = new $permissionModel;
        $roleIds = $permissionPrototype->getConnection()->table($pivotTable)
            ->where('model_type', $userClass)
            ->where('model_id', $userId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return PermissionSet::empty();
        }

        $keys = $permissionModel::query()
            ->whereIn('role_id', $roleIds)
            ->whereHas('role')
            ->where('panel_id', $panelId)
            ->pluck('permission_key')
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
