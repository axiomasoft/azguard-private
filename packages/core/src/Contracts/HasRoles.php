<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use AzGuard\Models\Role;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

/**
 * Public contract for the role surface of an AzGuard user.
 *
 * Mirrors the {@see \AzGuard\Concerns\HasRoles} trait 1:1.
 *
 * @api
 */
interface HasRoles
{
    /** @return MorphToMany<Model, Model> */
    public function roles(): MorphToMany;

    /** @return MorphMany<Model, Model> */
    public function scopes(): MorphMany;

    /**
     * @param  string|RoleInterface|class-string<RoleInterface>  $role
     */
    public function hasRole(string|RoleInterface $role): bool;

    public function assignRole(string|BackedEnum|Role ...$roles): static;

    public function removeRole(string|BackedEnum|Role ...$roles): static;

    /** @param array<string|BackedEnum|Role> $roles */
    public function syncRoles(array $roles): static;

    /** @return Collection<int, string> */
    public function getRoleNames(): Collection;
}
