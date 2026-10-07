<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Shop;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoles;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;

/**
 * Object source of the Shop module. The local name is the one the module declares.
 */
final class ShopPostsSource implements ProvidesPermissions, ProvidesRoles
{
    public function __construct(private readonly string $local = 'shop.posts.edit') {}

    public function id(): string
    {
        return 'shop-posts';
    }

    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        return [new PermissionDefinition($this->local, PermissionAuthority::Grants, label: 'Edit shop posts')];
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /**
     * @return iterable<BaseRole>
     */
    public function roles(Panel $panel): iterable
    {
        if ($this->local !== 'shop.posts.edit') {
            return [];
        }

        return [new ShopEditorRole];
    }
}
