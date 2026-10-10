<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog\Guards\Permissions\Posts;

use AzGuard\Permissions\Describe;
use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum PostPermission: string
{
    #[Describe('Edit')]
    case Edit = 'blog.posts.edit';
}
