<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use AzGuard\Changes\ChangeResult;
use AzGuard\Concerns\SubjectAccess;
use AzGuard\Concerns\SubjectPanels;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Kernel\Permissions\PermissionSet;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * A model that holds roles and permissions; `Concerns\HasAzGuard` implements it. The panel selector `guard()` is not
 * part of the contract: a model may keep its own Eloquent `guard()` and reach panels through `azguard()`.
 *
 * @api
 */
interface AzGuardSubject
{
    public function hasPermission(string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool;

    /** @param list<string|UnitEnum> $permissions */
    public function hasAnyPermission(array $permissions, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool;

    /** @param list<string|UnitEnum> $permissions */
    public function hasAllPermissions(array $permissions, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool;

    public function permissionSet(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): PermissionSet;

    /** @return Collection<int, string> */
    public function permissionNames(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): Collection;

    /** @param string|UnitEnum|list<string|UnitEnum> $roles */
    public function hasRole(string|UnitEnum|array $roles, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool;

    /** @param list<string|UnitEnum> $roles */
    public function hasAnyRole(array $roles, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool;

    /** @param list<string|UnitEnum> $roles */
    public function hasAllRoles(array $roles, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool;

    /** @return Collection<int, string> */
    public function roleNames(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): Collection;

    public function isSuperAdmin(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool;

    /**
     * @param  string|UnitEnum|list<string|UnitEnum>  $roles
     * @param  array<string, mixed>  $fields
     */
    public function grantRole(string|UnitEnum|array $roles, Model|AssignmentScopeRef|null $on = null, ?DateTimeInterface $until = null, array $fields = []): ChangeResult;

    public function revokeRole(string|UnitEnum $role, Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): ChangeResult;

    /** @param list<string|UnitEnum> $roles */
    public function syncRoles(array $roles, Model|AssignmentScopeRef|null $on = null): ChangeResult;

    /**
     * @param  string|UnitEnum|list<string|UnitEnum>  $permissions
     * @param  array<string, mixed>  $fields
     */
    public function grantPermission(string|UnitEnum|array $permissions, Model|AssignmentScopeRef|null $on = null, ?DateTimeInterface $until = null, array $fields = []): ChangeResult;

    public function revokePermission(string|UnitEnum $permission, Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): ChangeResult;

    /** @param list<string|UnitEnum> $permissions */
    public function syncPermissions(array $permissions, Model|AssignmentScopeRef|null $on = null): ChangeResult;

    public function inTenant(Model|TenantRef $tenant): SubjectAccess;

    public function azguard(): SubjectPanels;

    public function azguardDefaultPanel(): ?string;

    public function azguardRef(): SubjectRef;
}
