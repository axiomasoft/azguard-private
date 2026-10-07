<?php

declare(strict_types=1);

namespace AzGuard\Concerns;

use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\AzGuardSubject;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Kernel\Permissions\PermissionSet;
use AzGuard\Panels\PanelResolver;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Checks, roles and changes on any Eloquent model; implements {@see AzGuardSubject}.
 *
 * Every method asks the panel resolver for its panel: `guard:`, full names, prefixes, enums and role classes name it,
 * otherwise the panel of the request, otherwise the panel of the model. The method then runs in that panel exactly as
 * {@see SubjectAccess} does.
 *
 * `guard()` keeps the Eloquent meaning for an array — the guarded attributes of mass assignment — and selects a panel
 * for a string. A model that already overrides `guard()` keeps its method and reaches panels through `azguard()`.
 *
 * @mixin Model
 */
trait HasAzGuard
{
    // Permissions

    public function hasPermission(string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->azguardAccess($guard, [$permission])->hasPermission($permission, $on);
    }

    /** @param list<string|UnitEnum> $permissions */
    public function hasAnyPermission(array $permissions, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->azguardAccess($guard, $permissions)->hasAnyPermission($permissions, $on);
    }

    /** @param list<string|UnitEnum> $permissions */
    public function hasAllPermissions(array $permissions, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->azguardAccess($guard, $permissions)->hasAllPermissions($permissions, $on);
    }

    public function permissionSet(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): PermissionSet
    {
        return $this->azguardAccess($guard)->permissionSet($on);
    }

    /** @return Collection<int, string> */
    public function permissionNames(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): Collection
    {
        return $this->azguardAccess($guard)->permissionNames($on);
    }

    // Roles

    /** @param string|UnitEnum|list<string|UnitEnum> $roles a list holds when any of the roles does */
    public function hasRole(string|UnitEnum|array $roles, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->azguardAccess($guard, [], is_array($roles) ? $roles : [$roles])->hasRole($roles, $on);
    }

    /** @param list<string|UnitEnum> $roles */
    public function hasAnyRole(array $roles, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->azguardAccess($guard, [], $roles)->hasAnyRole($roles, $on);
    }

    /** @param list<string|UnitEnum> $roles */
    public function hasAllRoles(array $roles, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->azguardAccess($guard, [], $roles)->hasAllRoles($roles, $on);
    }

    /** @return Collection<int, string> */
    public function roleNames(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): Collection
    {
        return $this->azguardAccess($guard)->roleNames($on);
    }

    // Super admin

    public function isSuperAdmin(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->azguardAccess($guard)->isSuperAdmin($on);
    }

    // Changes

    /**
     * @param  string|UnitEnum|list<string|UnitEnum>  $roles
     * @param  array<string, mixed>  $fields
     */
    public function grantRole(string|UnitEnum|array $roles, Model|AssignmentScopeRef|null $on = null, ?DateTimeInterface $until = null, array $fields = []): ChangeResult
    {
        return $this->azguardAccess(null, [], is_array($roles) ? $roles : [$roles])->grantRole($roles, $on, $until, $fields);
    }

    public function revokeRole(string|UnitEnum $role, Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): ChangeResult
    {
        return $this->azguardAccess(null, [], [$role])->revokeRole($role, $on);
    }

    /** @param list<string|UnitEnum> $roles */
    public function syncRoles(array $roles, Model|AssignmentScopeRef|null $on = null): ChangeResult
    {
        return $this->azguardAccess(null, [], $roles)->syncRoles($roles, $on);
    }

    /**
     * @param  string|UnitEnum|list<string|UnitEnum>  $permissions
     * @param  array<string, mixed>  $fields
     */
    public function grantPermission(string|UnitEnum|array $permissions, Model|AssignmentScopeRef|null $on = null, ?DateTimeInterface $until = null, array $fields = []): ChangeResult
    {
        return $this->azguardAccess(null, is_array($permissions) ? $permissions : [$permissions])->grantPermission($permissions, $on, $until, $fields);
    }

    public function revokePermission(string|UnitEnum $permission, Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): ChangeResult
    {
        return $this->azguardAccess(null, [$permission])->revokePermission($permission, $on);
    }

    /** @param list<string|UnitEnum> $permissions */
    public function syncPermissions(array $permissions, Model|AssignmentScopeRef|null $on = null): ChangeResult
    {
        return $this->azguardAccess(null, $permissions)->syncPermissions($permissions, $on);
    }

    // Panels

    /** The subject in the panel of the request or of the model, in a tenant. */
    public function inTenant(Model|TenantRef $tenant): SubjectAccess
    {
        return $this->azguardAccess(null)->inTenant($tenant);
    }

    /**
     * The guarded attributes of mass assignment for an array, as Eloquent; the subject in a panel for a string.
     *
     * @param  array<string>|string  $guarded
     * @return ($guarded is array ? static : SubjectAccess)
     */
    public function guard(array|string $guarded): static|SubjectAccess
    {
        if (is_array($guarded)) {
            parent::guard($guarded);

            return $this;
        }

        return $this->azguard()->guard($guarded);
    }

    public function azguard(): SubjectPanels
    {
        return new SubjectPanels($this);
    }

    /** The panel this model uses when nothing names one and the request has none; null leaves it to the panels. */
    public function azguardDefaultPanel(): ?string
    {
        return null;
    }

    public function azguardRef(): SubjectRef
    {
        return SubjectRef::of($this->getMorphClass(), $this->getKey());
    }

    /**
     * @param  list<string|UnitEnum>  $permissions
     * @param  list<string|UnitEnum>  $roles
     */
    private function azguardAccess(?string $guard, array $permissions = [], array $roles = []): SubjectAccess
    {
        return new SubjectAccess(app(PanelResolver::class)->select($this, $permissions, $roles, $guard === null ? [] : [$guard]), $this);
    }
}
