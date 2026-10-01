<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Roles;

use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Stubs\Permissions\TestPermission;

/**
 * Enum-first scoped role: declares its permissions as list<UnitEnum> — the
 * documented PREFERRED form. The owning panel ('test') scopes each case to
 * "test.{value}". Used to prove hasScopedPermission resolves enum cases via
 * the panel BEFORE comparing (previously silently denied — in_array on the
 * raw enum list against a resolved string key was always false).
 */
class EnumScopedRole extends BaseRole
{
    public function permissions(): array
    {
        return [TestPermission::PostView, TestPermission::PostCreate];
    }
}
