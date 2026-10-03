<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Permissions;

use AzGuard\Policies\Decides;

final class AttributedClientPolicy
{
    #[Decides(ClientPermission::ViewOwnProfile)]
    public function ownProfile(): bool
    {
        return false;
    }

    #[Decides(ClientPermission::Update)]
    public function update(): bool
    {
        return false;
    }

    #[Decides('clients.own')]
    public function own(): bool
    {
        return false;
    }
}
