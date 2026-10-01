<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Permissions;

use AzGuard\Attributes\RoleOnly;

enum TestPermission: string
{
    case PostView = 'post.view';

    #[RoleOnly]
    case PostCreate = 'post.create';

    #[RoleOnly]
    case PostEdit = 'post.edit';

    #[RoleOnly]
    case PostDelete = 'post.delete';
}
