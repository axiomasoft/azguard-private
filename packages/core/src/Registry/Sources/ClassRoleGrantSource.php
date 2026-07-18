<?php

declare(strict_types=1);

namespace AzGuard\Registry\Sources;

use AzGuard\Concerns\HasRoles;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Contracts\RoleInterface;
use AzGuard\Models\Role;
use AzGuard\Panels\Panel;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Registry\Contracts\GrantPriority;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;
use UnitEnum;

/**
 * Grant source from PHP role classes (RoleInterface::permissions()).
 *
 * A role may declare its permissions as enum cases (preferred, refactor-safe)
 * or as already-resolved panel-prefixed string keys. Enum cases are scoped to
 * their owning panel automatically, so a role only needs to list the bare
 * permission cases — never the "{panel}." prefix.
 *
 * Only processes roles that have a class_name set (code-defined roles).
 * Pure DB roles without a class_name are handled by DatabaseRoleGrantSource.
 * Priority: 100 (highest).
 */
final readonly class ClassRoleGrantSource implements GrantSource
{
    public function __construct(
        private AzGuardManagerInterface $manager,
    ) {}

    #[Override]
    public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
    {
        // Gate on HasRoles (the trait that actually provides $user->roles), so
        // a user that uses HasRoles directly — not only the HasAzGuard composite
        // — still resolves its class roles. The Model gate is fail-closed: the
        // trait's relation cannot exist outside an Eloquent model.
        if (! $user instanceof Model
            || ! in_array(HasRoles::class, class_uses_recursive($user), strict: true)) {
            return PermissionSet::empty();
        }

        // Same runtime path as $user->roles (relation-cache included) — the
        // trait guarantees the relation, mirrored 1:1 by the
        // AzGuard\Contracts\HasRoles contract (parity-tested).
        /** @var Collection<int, Role> $roles */
        $roles = $user->getAttribute('roles');

        $keys = $roles
            ->filter(fn ($role): bool => $role->class_name !== null)
            ->map(fn ($role) => $role->getRoleLogic())
            ->filter()
            ->flatMap(fn (RoleInterface $roleLogic): array => $this->resolveFor(
                roleLogic: $roleLogic,
                panelId: $panelId,
            ))
            ->unique()
            ->values()
            ->all();

        if (in_array(PermissionKey::WILDCARD, $keys, strict: true)) {
            return PermissionSet::wildcard();
        }

        return PermissionSet::fromKeys($keys);
    }

    /**
     * Normalise a role's declared permissions to the full keys that belong to
     * the queried panel. Enum cases are scoped via their owning panel; strings
     * are kept when they are the wildcard or already prefixed with the panel.
     *
     * This is the single source of truth for enum -> string permission
     * normalisation; callers outside this class (e.g. entity-scoped role
     * checks) must route through here rather than re-implementing in_array
     * against a raw RoleInterface::permissions() list.
     *
     * @return list<string>
     */
    public function resolveFor(RoleInterface $roleLogic, string $panelId): array
    {
        $panel = $this->manager->panel($panelId);
        $panelEnums = $panel?->getPermissionEnums() ?? [];

        return $this->resolvePermissions(
            permissions: $roleLogic->permissions(),
            panelId: $panelId,
            panel: $panel,
            panelEnums: $panelEnums,
        );
    }

    /**
     * @param  list<UnitEnum|string>  $permissions
     * @param  list<class-string>  $panelEnums
     * @return list<string>
     */
    private function resolvePermissions(array $permissions, string $panelId, ?Panel $panel, array $panelEnums): array
    {
        $keys = [];

        foreach ($permissions as $permission) {
            if ($permission instanceof UnitEnum) {
                // Include an enum case only when it belongs to the queried panel,
                // scoped to its full "{panelId}.{value}" key.
                if ($panel instanceof Panel && in_array($permission::class, $panelEnums, strict: true)) {
                    $keys[] = $panel->resolvePermission(permission: $permission);
                }

                continue;
            }

            if ($permission === PermissionKey::WILDCARD || str_starts_with($permission, $panelId.PermissionKey::SEPARATOR)) {
                $keys[] = $permission;
            }
        }

        return $keys;
    }

    #[Override]
    public function priority(): int
    {
        return GrantPriority::ClassRole->value;
    }
}
