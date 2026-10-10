<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/**
 * The editor of the Blog module. The module chooses the key; a plugin does not prefix it.
 */
#[Role('blog-editor', label: 'Blog editor')]
final class BlogEditorRole extends BaseRole
{
    public function permissions(): array
    {
        return ['blog.posts.edit'];
    }
}
