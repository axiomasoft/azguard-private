<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Permissions\ClientPermission;

/**
 * Lists a permission that its policy alone decides; a panel refuses to compile it.
 */
#[Role('profile-viewer')]
final class ProfileViewerRole extends BaseRole
{
    public function permissions(): array
    {
        return [ClientPermission::ViewOwnProfile];
    }
}
