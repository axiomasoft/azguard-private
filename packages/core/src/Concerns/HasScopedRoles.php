<?php

declare(strict_types=1);

namespace AzGuard\Concerns;

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Models\ModelHasScope;
use AzGuard\Models\Role;
use AzGuard\PermissionKey;
use AzGuard\Registry\Sources\ClassRoleGrantSource;
use AzGuard\Support\Config;
use AzGuard\Support\PanelResolver;
use AzGuard\Support\PermissionName;
use AzGuard\Support\ScopedRoleCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Adds entity-scoped role support to Eloquent models.
 *
 * Named "ScopedRoles" rather than "Scopes" to avoid clashing with
 * Laravel's own "scope" terminology for Eloquent query scopes.
 *
 * Provides:
 * - assignScopedRole()          — assign a role scoped to a specific entity
 * - removeScopedRole()          — remove a scoped role assignment (its own panel, or the any-panel row)
 * - removeScopedRoleEverywhere() — remove a scoped role assignment across every panel at once
 * - hasScopedRole()             — check if user has a role for a specific entity
 * - hasScopedPermission()       — check permission within a specific entity scope
 *
 * Depends on HasAzGuard::resolveRole() being available on the same model.
 */
trait HasScopedRoles
{
    /**
     * The Eloquent global scope key used to filter query results
     * based on the authenticated user's scoped roles.
     *
     * Use this constant when calling Model::withoutGlobalScope():
     *   Model::withoutGlobalScope(HasScopedRoles::SCOPE_KEY);
     */
    public const SCOPE_KEY = 'azguard_scope_filter';

    public static function bootHasScopedRoles(): void
    {
        static::addGlobalScope(self::SCOPE_KEY, function (Builder $builder): void {
            if (app()->runningInConsole() || ! Auth::check()) {
                return;
            }

            $user = Auth::user();

            if (! method_exists($user, 'scopes')) {
                return;
            }

            $currentPanelId = PanelResolver::resolve(null);

            $scopes = app(ScopedRoleCache::class)->remember(
                $user->getAuthIdentifier().'|'.static::class,
                // eager-load to avoid a query per scope row below; withoutGlobalScope prevents
                // infinite recursion when scopeEntity is itself a HasScopedRoles model (this scope).
                fn () => $user->scopes()
                    ->where('scope_entity_type', static::class)
                    ->with(['scopeEntity' => fn ($q) => $q->withoutGlobalScope(self::SCOPE_KEY)])
                    ->get(),
            );

            foreach ($scopes as $scope) {
                // No current panel => apply every scope (back-compat, D5). A panel-scoped
                // row only drops out when a DIFFERENT panel is active.
                if ($currentPanelId !== null && $scope->panel_id !== null && $scope->panel_id !== $currentPanelId) {
                    continue;
                }

                // A null scope_class indicates a logic-less role (no query-scope behavior).
                // Only apply the scope filter if a scope_class exists and is instantiable.
                if ($scope->scope_class !== null && class_exists($scope->scope_class)) {
                    app($scope->scope_class)->apply($builder, $user, $scope->scopeEntity);
                }
            }
        });
    }

    /**
     * Assign a role scoped to a specific entity.
     *
     *   $user->assignScopedRole('editor', $project);
     *   $user->assignScopedRole('editor', $project, panelId: 'admin');
     *
     * A null $panelId (the default) persists as "any panel" for back-compat:
     * the assignment is honoured regardless of which panel is being checked.
     * Pass an explicit $panelId to isolate the assignment to that panel only.
     *
     * For logic-less roles (where getRoleLogic() returns null), scope_class
     * is stored as null to avoid the fragile anonymous-class sentinel pattern.
     */
    public function assignScopedRole(string|Role $role, Model $entity, ?string $panelId = null): static
    {
        $roleModel = $this->resolveRole($role);

        if ($roleModel === null) {
            return $this;
        }

        $roleLogic = $roleModel->getRoleLogic();

        ModelHasScope::firstOrCreate([
            'model_type' => $this->getMorphClass(),
            'model_id' => $this->getKey(),
            'scope_entity_type' => $entity->getMorphClass(),
            'scope_entity_id' => $entity->getKey(),
            'role_id' => $roleModel->getKey(),
            'panel_id' => $panelId,
        ], [
            'scope_class' => $roleLogic !== null ? $roleLogic::class : null,
        ]);

        $this->flushPermissions();

        return $this;
    }

    /**
     * Remove a scoped role for a specific entity.
     *
     *   $user->removeScopedRole('editor', $project);
     *   $user->removeScopedRole('editor', $project, panelId: 'admin');
     *
     * A null $panelId (the default) removes ONLY the any-panel row
     * (panel_id IS NULL) — symmetric with assignScopedRole(), where a null
     * $panelId also targets the any-panel row. Pass an explicit $panelId to
     * remove only the assignment scoped to that panel. To remove the role
     * across EVERY panel in one call, use removeScopedRoleEverywhere().
     *
     * @see removeScopedRoleEverywhere()
     */
    public function removeScopedRole(string|Role $role, Model $entity, ?string $panelId = null): static
    {
        $roleModel = $this->resolveRole($role);

        if ($roleModel === null) {
            return $this;
        }

        ModelHasScope::query()
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->where('scope_entity_type', $entity->getMorphClass())
            ->where('scope_entity_id', $entity->getKey())
            ->where('role_id', $roleModel->getKey())
            ->when(
                $panelId !== null,
                fn (Builder $query): Builder => $query->where('panel_id', $panelId),
                fn (Builder $query): Builder => $query->whereNull('panel_id'),
            )
            ->delete();

        $this->flushPermissions();

        return $this;
    }

    /**
     * Remove a scoped role for a specific entity across EVERY panel,
     * regardless of panel_id.
     *
     *   $user->removeScopedRoleEverywhere('editor', $project);
     *
     * This is the pre-D10 removeScopedRole(panelId: null) behavior, split
     * out into its own explicit method now that removeScopedRole(panelId:
     * null) targets only the any-panel row.
     */
    public function removeScopedRoleEverywhere(string|Role $role, Model $entity): static
    {
        $roleModel = $this->resolveRole($role);

        if ($roleModel === null) {
            return $this;
        }

        ModelHasScope::query()
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->where('scope_entity_type', $entity->getMorphClass())
            ->where('scope_entity_id', $entity->getKey())
            ->where('role_id', $roleModel->getKey())
            ->delete();

        $this->flushPermissions();

        return $this;
    }

    /**
     * Check if user has a specific role scoped to an entity.
     *
     *   $user->hasScopedRole('editor', $project);
     *
     * When $panelId is null (the default), matches assignments regardless of
     * their panel_id. Pass an explicit $panelId to require that panel — a
     * scope with a null panel_id still matches (any-panel back-compat).
     */
    public function hasScopedRole(string|Role $role, Model $entity, ?string $panelId = null): bool
    {
        $roleModel = $this->resolveRole($role);

        if ($roleModel === null) {
            return false;
        }

        return ModelHasScope::query()
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->where('scope_entity_type', $entity->getMorphClass())
            ->where('scope_entity_id', $entity->getKey())
            ->where('role_id', $roleModel->getKey())
            ->when(
                $panelId !== null,
                fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query
                    ->whereNull('panel_id')
                    ->orWhere('panel_id', $panelId)),
            )
            ->exists();
    }

    /**
     * Check if user has a permission within a specific entity scope.
     *
     *   $user->hasScopedPermission('app.projects.edit', $project);
     *   $user->hasScopedPermission(ProjectPermission::Edit, $project, 'app');
     *
     * Resolution order:
     *   1. SuperAdmin global wildcard (*) — always granted via hasPermission()
     *   2. Scoped roles for the given entity, isolated to the resolved panel
     *
     * Panel resolution: an explicit $panelId always wins. Otherwise the panel is
     * taken from a scoped string key's first segment ("app.projects.edit" -> "app"),
     * or — for an enum permission — from the panel that owns the enum, falling back
     * to az-guard.default_panel. Pass $panelId explicitly for scopedByPanelId(false)
     * panels or an enum registered on more than one panel.
     *
     * Panel isolation: a scoped role assigned under panel A is NOT honoured when
     * checked against panel B. A scope with a null panel_id (assigned before this
     * isolation was added, or intentionally left unscoped) is honoured for ANY
     * panel — back-compat.
     */
    public function hasScopedPermission(string|UnitEnum $permission, Model $entity, ?string $panelId = null): bool
    {
        if ($panelId === null) {
            $panelId = match (true) {
                is_string($permission) && str_contains($permission, PermissionKey::SEPARATOR) => explode(PermissionKey::SEPARATOR, $permission)[0],
                $permission instanceof UnitEnum => app(AzGuardManagerInterface::class)->panelIdForPermission($permission) ?? PanelResolver::resolveDefault(null),
                default => PanelResolver::resolveDefault(null),
            };
        }

        $key = PermissionName::resolve($permission, $panelId);

        if (method_exists($this, 'hasPermission') && $this->hasPermission($key, $panelId)) {
            return true;
        }

        $scopedRoleIds = ModelHasScope::query()
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->where('scope_entity_type', $entity->getMorphClass())
            ->where('scope_entity_id', $entity->getKey())
            ->whereNotNull('role_id')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('panel_id')
                ->orWhere('panel_id', $panelId))
            ->pluck('role_id');

        if ($scopedRoleIds->isEmpty()) {
            return false;
        }

        /** @var class-string<Role> $roleClass */
        $roleClass = Config::roleModel();

        $roles = $roleClass::query()->whereIn('id', $scopedRoleIds)->get();

        $grantSource = app(ClassRoleGrantSource::class);

        foreach ($roles as $roleModel) {
            $logic = $roleModel->getRoleLogic();

            if ($logic === null) {
                continue;
            }

            // Route through the single enum -> string resolution seam
            // (ClassRoleGrantSource::resolveFor) rather than comparing $key
            // against the raw RoleInterface::permissions() list, which may
            // contain unresolved UnitEnum cases.
            $resolvedKeys = $grantSource->resolveFor(roleLogic: $logic, panelId: $panelId);

            if (in_array(PermissionKey::WILDCARD, $resolvedKeys, true) || in_array($key, $resolvedKeys, true)) {
                return true;
            }
        }

        return false;
    }
}
