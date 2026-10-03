<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog;

/**
 * Permission enum of the Blog module. A panel receives it through `permissions([...])`.
 */
enum BlogPermission: string
{
    case Edit = 'blog.posts.edit';
}
