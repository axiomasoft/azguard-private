<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog;

use AzGuard\Permissions\RequiresGrant;

/**
 * Permission enum of the Blog module. A panel receives it through `permissions([...])`.
 */
#[RequiresGrant]
enum BlogPermission: string
{
    case Edit = 'blog.posts.edit';
}
