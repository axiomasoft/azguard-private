<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesRoles;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;

/**
 * Object source the Blog plugin brings: one permission and one role, under the names the module chose.
 */
final class BlogPostsSource implements ProvidesPermissions, ProvidesRoles
{
    public function id(): string
    {
        return 'blog-posts';
    }

    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        return [new PermissionDefinition('blog.posts.edit', PermissionAuthority::Grants, label: 'Edit blog posts')];
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
        return [new BlogEditorRole];
    }
}
