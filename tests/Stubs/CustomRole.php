<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Models\Role;

/**
 * Custom role model stub for proving RolePermissionsCommand resolves its role
 * model through Config::roleModel() (not the hardcoded Role class). Shares the
 * same table as Role but flips a static flag whenever it is retrieved from the
 * database, making the choice of model observable from a test: if the command
 * used the hardcoded Role model, the flag would stay false.
 */
class CustomRole extends Role
{
    public static bool $retrieved = false;

    protected $table = 'roles';

    protected static function booted(): void
    {
        parent::booted();

        static::retrieved(static function (): void {
            self::$retrieved = true;
        });
    }
}
