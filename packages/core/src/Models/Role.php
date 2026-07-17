<?php

declare(strict_types=1);

namespace AzGuard\Models;

use AzGuard\Contracts\RoleInterface;
use AzGuard\Support\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $level
 * @property string|null $class_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends Model
{
    protected $fillable = ['name', 'level', 'class_name'];

    /** @return MorphToMany<Model, $this> */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            config('auth.providers.users.model'),
            'model',
            Config::modelHasRolesTable(),
        );
    }

    /**
     * Permissions assigned to the role via the DB (not via PHP class).
     * Used by DatabaseRoleGrantSource.
     *
     * @return HasMany<RolePermission, $this>
     */
    public function dbPermissions(): HasMany
    {
        return $this->hasMany(
            Config::rolePermissionModel(),
            'role_id',
        );
    }

    /**
     * Instantiate the role logic class (e.g. SuperAdminRole).
     *
     * Returns null when class_name is unset, missing, or does not implement
     * RoleInterface — so callers can rely on the contract without a fatal on a
     * stale/invalid class_name.
     */
    public function getRoleLogic(): ?RoleInterface
    {
        if (! is_string($this->class_name) || ! is_subclass_of($this->class_name, RoleInterface::class)) {
            return null;
        }

        return new $this->class_name;
    }

    /**
     * Check whether the role has a DB permission for the given panel.
     */
    public function hasDbPermission(string $permissionKey, string $panelId): bool
    {
        return $this->dbPermissions()
            ->where('permission_key', $permissionKey)
            ->where('panel_id', $panelId)
            ->exists();
    }

    /**
     * Find a role by its name. Consolidates resolveRole() / resolveScopeRole() across traits.
     */
    public static function findByName(string $name): ?static
    {
        return static::query()->where('name', $name)->first();
    }
}
