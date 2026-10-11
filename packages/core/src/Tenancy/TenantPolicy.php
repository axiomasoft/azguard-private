<?php

declare(strict_types=1);

namespace AzGuard\Tenancy;

use AzGuard\Attributes\Api;
use AzGuard\Contracts\Tenancy\TenantMembership;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Roles\BaseRole;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant boundary of a panel. Required tenants also require active membership by default.
 */
#[Api(since: '1.0')]
final class TenantPolicy
{
    /** @var list<class-string<BaseRole>> */
    private array $globalRoles = [];

    /** @param TenantMembership|class-string<TenantMembership>|null $membership */
    private function __construct(
        private readonly TenantMode $mode,
        private readonly ?ModelTenantDefinition $definition,
        private TenantMembership|string|null $membership,
    ) {}

    public static function none(): self
    {
        return new self(mode: TenantMode::None, definition: null, membership: null);
    }

    /** @param class-string<Model>|ModelTenantDefinition $tenant */
    public static function required(string|ModelTenantDefinition $tenant): self
    {
        return new self(
            mode: TenantMode::Required,
            definition: is_string($tenant) ? ModelTenantDefinition::make($tenant) : $tenant,
            membership: TenantMembership::class,
        );
    }

    /** @param TenantMembership|class-string<TenantMembership> $membership */
    public function requireMembership(TenantMembership|string $membership = TenantMembership::class): self
    {
        if (is_string($membership)) {
            $membership = $this->membershipClass($membership);
        }

        $copy = clone $this;
        $copy->membership = $membership;

        return $copy;
    }

    /** @param list<class-string<BaseRole>> $roles */
    public function allowGlobalRoles(array $roles): self
    {
        foreach ($this->untrusted($roles) as $role) {
            if (! is_string($role) || ! is_subclass_of($role, BaseRole::class)) {
                throw new DefinitionException('Global tenant roles must extend '.BaseRole::class.'.');
            }
        }

        $copy = clone $this;
        $copy->globalRoles = array_values(array_unique($roles));

        return $copy;
    }

    /** @return class-string<TenantMembership> */
    private function membershipClass(string $class): string
    {
        if (! is_a($class, TenantMembership::class, true)) {
            throw new DefinitionException('Tenant membership must implement '.TenantMembership::class.'.');
        }

        return $class;
    }

    /** @param array<mixed> $roles
     * @return array<mixed>
     */
    private function untrusted(array $roles): array
    {
        return $roles;
    }

    public function mode(): TenantMode
    {
        return $this->mode;
    }

    public function definition(): ?ModelTenantDefinition
    {
        return $this->definition;
    }

    /** @return TenantMembership|class-string<TenantMembership>|null */
    public function membership(): TenantMembership|string|null
    {
        return $this->membership;
    }

    /** @return list<class-string<BaseRole>> */
    public function globalRoles(): array
    {
        return $this->globalRoles;
    }
}
