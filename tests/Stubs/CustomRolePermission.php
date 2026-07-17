<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Models\RolePermission;

/**
 * Custom role-permission model stub for proving RolePermissionsCommand persists
 * through Config::rolePermissionModel() (not the hardcoded RolePermission
 * class). Shares the same table but flips a static flag on every create, making
 * the choice of model observable: if the command used the hardcoded model, the
 * flag would stay false.
 */
class CustomRolePermission extends RolePermission
{
    public static bool $created = false;

    protected static function booted(): void
    {
        parent::booted();

        static::created(static function (): void {
            self::$created = true;
        });
    }
}
