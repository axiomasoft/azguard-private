<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Models\RolePermission;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hides permission rows whose key starts with "secret." — used to prove
 * DatabaseRoleGrantSource honours RolePermission global scopes.
 */
class HiddenPermissionRolePermission extends RolePermission
{
    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('hide-secret', static function (Builder $builder): void {
            $table = $builder->getModel()->getTable();
            $builder->where($table.'.permission_key', 'not like', 'secret.%');
        });
    }
}
