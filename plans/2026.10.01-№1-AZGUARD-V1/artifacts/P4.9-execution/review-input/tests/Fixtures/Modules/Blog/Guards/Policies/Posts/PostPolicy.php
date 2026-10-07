<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog\Guards\Policies\Posts;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Modules\Blog\Guards\Permissions\Posts\PostPermission;

final class PostPolicy
{
    #[Decides(PostPermission::Edit)]
    public function updatePost(): bool
    {
        return true;
    }
}
