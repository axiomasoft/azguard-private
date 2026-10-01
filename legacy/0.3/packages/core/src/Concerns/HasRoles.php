<?php

declare(strict_types=1);

namespace AzGuard\Concerns;

use AzGuard\Configuration\Config;
use AzGuard\Contracts\RoleInterface;
use AzGuard\Events\RoleAttached;
use AzGuard\Events\RoleDetached;
use AzGuard\Models\Role;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use BackedEnum;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

trait HasRoles
{
    use ResolvesRole;

    public function roles(): MorphToMany
    {
        return $this->morphToMany(
            Config::roleModel(),
            'model',
            Config::modelHasRolesTable(),
            'model_id',
            'role_id',
        );
    }

    public function scopes(): MorphMany
    {
        return $this->morphMany(Config::scopeModel(), 'model');
    }

    /**
     * Check whether the user has a role.
     *
     * Accepts a role class-string (preferred — refactor-safe), a RoleInterface
     * instance, a backed enum (unwrapped via its ->value, B-04), or a plain
     * role name.
     *
     * @param  string|BackedEnum|RoleInterface|class-string<RoleInterface>  $role
     */
    public function hasRole(string|BackedEnum|RoleInterface $role): bool
    {
        if ($role instanceof RoleInterface) {
            return $this->roles->contains('class_name', $role::class);
        }

        if ($role instanceof BackedEnum) {
            return $this->roles->contains('name', PermissionKey::normalize($role));
        }

        if (is_subclass_of($role, RoleInterface::class)) {
            return $this->roles->contains('class_name', $role);
        }

        return $this->roles->contains('name', $role);
    }

    public function assignRole(string|BackedEnum|Role ...$roles): static
    {
        $state = app(PermissionStateRevision::class);
        $state->assertSameConnection($this);

        return $state->mutate(function () use ($roles): array {
            $changed = false;

            foreach ($roles as $role) {
                $roleModel = $this->resolveRole($role);

                if ($roleModel === null) {
                    continue;
                }

                app(PermissionStateRevision::class)->assertSameConnection($roleModel);

                $sync = $this->roles()->syncWithoutDetaching([$roleModel->getKey()]);

                if (($sync['attached'] ?? []) !== []) {
                    $changed = true;
                    event(new RoleAttached($this, $roleModel));
                }
            }

            $this->flushPermissions();
            $this->unsetRelation('roles');

            return [$this, $changed];
        });
    }

    public function removeRole(string|BackedEnum|Role ...$roles): static
    {
        $state = app(PermissionStateRevision::class);
        $state->assertSameConnection($this);

        return $state->mutate(function () use ($roles): array {
            $changed = false;

            foreach ($roles as $role) {
                $roleModel = $this->resolveRole($role);

                if ($roleModel === null) {
                    continue;
                }

                app(PermissionStateRevision::class)->assertSameConnection($roleModel);

                $detached = $this->roles()->detach($roleModel->getKey());

                if ($detached > 0) {
                    $changed = true;
                    event(new RoleDetached($this, $roleModel));
                }
            }

            $this->flushPermissions();
            $this->unsetRelation('roles');

            return [$this, $changed];
        });
    }

    /** @param array<string|BackedEnum|Role> $roles */
    public function syncRoles(array $roles): static
    {
        $state = app(PermissionStateRevision::class);
        $state->assertSameConnection($this);

        return $state->mutate(function () use ($roles): array {
            $roleIds = [];

            foreach ($roles as $role) {
                $roleModel = $this->resolveRole($role);

                if ($roleModel !== null) {
                    app(PermissionStateRevision::class)->assertSameConnection($roleModel);
                    $roleIds[] = $roleModel->getKey();
                }
            }

            $changes = $this->roles()->sync($roleIds);

            // Batch-load all affected roles in 1 query instead of N separate Role::find() calls.
            $allAffectedIds = array_merge($changes['detached'], $changes['attached']);

            if ($allAffectedIds !== []) {
                $roleClass = Config::roleModel();
                $roleModels = $roleClass::query()->whereIn('id', $allAffectedIds)->get()->keyBy('id');

                foreach ($changes['detached'] as $id) {
                    if ($role = $roleModels->get($id)) {
                        event(new RoleDetached($this, $role));
                    }
                }

                foreach ($changes['attached'] as $id) {
                    if ($role = $roleModels->get($id)) {
                        event(new RoleAttached($this, $role));
                    }
                }
            }

            $this->flushPermissions();
            $this->unsetRelation('roles');

            return [$this, $allAffectedIds !== []];
        });
    }

    /** @return Collection<int, string> */
    public function getRoleNames(): Collection
    {
        return $this->roles->pluck('name');
    }
}
