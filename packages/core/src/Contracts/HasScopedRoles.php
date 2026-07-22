<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use AzGuard\Models\Role;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Public contract for entity-scoped roles.
 *
 * Mirrors the {@see \AzGuard\Concerns\HasScopedRoles} trait 1:1. Opt in by
 * declaring this interface and `use`-ing the trait alongside {@see AzGuardUser}
 * when you need entity-scoped role checks.
 *
 * $entity is typed as an Eloquent Model — the idiomatic scope subject for a
 * Laravel package. Framework-agnostic integrators should adapt through their own
 * boundary rather than expect AzGuard to widen its public type.
 *
 * @api
 */
interface HasScopedRoles
{
    public function assignScopedRole(string|BackedEnum|Role $role, Model $entity, string|BackedEnum|null $panelId = null): static;

    public function removeScopedRole(string|BackedEnum|Role $role, Model $entity, string|BackedEnum|null $panelId = null): static;

    public function hasScopedRole(string|BackedEnum|Role $role, Model $entity, string|BackedEnum|null $panelId = null): bool;

    public function hasScopedPermission(string|UnitEnum $permission, Model $entity, string|BackedEnum|null $panelId = null): bool;
}
