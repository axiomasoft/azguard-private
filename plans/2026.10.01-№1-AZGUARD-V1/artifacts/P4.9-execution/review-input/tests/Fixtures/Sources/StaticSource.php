<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesPolicies;
use AzGuard\Contracts\Sources\ProvidesRoles;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Roles\BaseRole;

/**
 * A static source whose permissions, roles and policy bindings are given by the test.
 */
final class StaticSource implements ProvidesPermissions, ProvidesPolicies, ProvidesRoles
{
    /** How many times permissions() was read, by source id. */
    public static array $reads = [];

    /**
     * @param  list<mixed>  $permissions
     * @param  list<mixed>  $roles
     * @param  list<mixed>  $policies
     */
    public function __construct(
        private readonly string $id = 'static',
        private readonly array $permissions = [],
        private readonly array $roles = [],
        private readonly array $policies = [],
        private readonly bool $dynamic = false,
    ) {}

    /**
     * A source of grants permissions with the given local names.
     */
    public static function names(string $id, string ...$locals): self
    {
        return new self($id, array_map(static fn (string $local): PermissionDefinition => self::grants($local), array_values($locals)));
    }

    public static function grants(string $local, ?string $label = null): PermissionDefinition
    {
        return new PermissionDefinition($local, PermissionAuthority::Grants, label: $label);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        self::$reads[$this->id] = (self::$reads[$this->id] ?? 0) + 1;

        return $this->permissions;
    }

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    /**
     * @return iterable<BaseRole>
     */
    public function roles(Panel $panel): iterable
    {
        return $this->roles;
    }

    /**
     * @return iterable<PolicyBinding>
     */
    public function policies(Panel $panel): iterable
    {
        return $this->policies;
    }
}
