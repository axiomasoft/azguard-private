<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog\Guards\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Modules\Blog\Guards\Permissions\Posts\PostPermission;

#[Role('blog-editor', label: 'Blog editor')]
final class BlogEditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [PostPermission::Edit, 'blog.posts.edit'];
    }
}
