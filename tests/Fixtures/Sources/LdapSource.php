<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources;

use AzGuard\Attributes\AsSource;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;

/**
 * A named source. Every instance is recorded so a test can see that panels do not share one.
 */
#[AsSource('ldap')]
final class LdapSource implements ProvidesPermissions, ProvidesRoleGrants
{
    /** @var list<self> */
    public static array $made = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = [])
    {
        self::$made[] = $this;
    }

    public function id(): string
    {
        return 'ldap';
    }

    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        return [new PermissionDefinition('ldap.sync', PermissionAuthority::Grants)];
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function roleGrants(mixed $subject, array $scopes, mixed $context): iterable
    {
        return [];
    }

    public function volatility(): Volatility
    {
        return Volatility::Request;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }
}
