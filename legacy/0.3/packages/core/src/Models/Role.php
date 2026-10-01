<?php

declare(strict_types=1);

namespace AzGuard\Models;

use AzGuard\Configuration\Config;
use AzGuard\Contracts\RoleInterface;
use AzGuard\Support\RoleIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Override;

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
    /**
     * class_name is deliberately NOT fillable (C-11): it wires the role to a
     * PHP RoleInterface class (getRoleLogic()) and must only be set through
     * trusted internal paths (e.g. RoleAssignmentCommand's direct setter),
     * never from mass-assigned request input.
     */
    protected $fillable = ['name', 'level'];

    #[Override]
    public function getTable(): string
    {
        return Config::rolesTable();
    }

    /** @return MorphToMany<Model, $this> */
    public function users(): MorphToMany
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->morphedByMany(
            $userModel,
            'model',
            Config::modelHasRolesTable(),
            'role_id',
            'model_id',
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
     * Returns null only for DB-only roles (`class_name` null). A non-null
     * missing or non-contract class raises InvalidRoleClassException.
     */
    public function getRoleLogic(): ?RoleInterface
    {
        if ($this->class_name === null) {
            return null;
        }

        return RoleIdentity::logicOrFail($this->class_name);
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
