<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use Closure;

class GeneratedSource implements ProvidesGrants, ProvidesPermissions, ProvidesRoleGrants
{
    public int $grantReads = 0;

    public int $roleReads = 0;

    public function __construct(public string $name = 'generated', public array $direct = [], public array $roles = [], public ?Closure $read = null) {}

    public function id(): string
    {
        return $this->name;
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        yield new PermissionDefinition('orders.view', PermissionAuthority::Grants);
        yield new PermissionDefinition('orders.edit', PermissionAuthority::Grants);
        yield new PermissionDefinition('orders.policy', PermissionAuthority::Policy);
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->grantReads++;

        if ($this->read !== null) {
            yield from ($this->read)($subject, $scopes, $context);

            return;
        }
        yield from $this->direct;
    }

    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->roleReads++;
        yield from $this->roles;
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }
}
