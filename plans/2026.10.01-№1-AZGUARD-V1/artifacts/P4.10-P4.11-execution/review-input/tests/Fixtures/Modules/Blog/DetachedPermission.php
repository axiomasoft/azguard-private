<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog;

/**
 * A permission enum the Blog module declares and no panel lists.
 */
enum DetachedPermission: string
{
    case Publish = 'blog.posts.publish';
}
