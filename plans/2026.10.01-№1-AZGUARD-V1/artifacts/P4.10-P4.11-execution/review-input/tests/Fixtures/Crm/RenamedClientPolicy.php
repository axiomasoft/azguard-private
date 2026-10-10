<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\User;

final class RenamedClientPolicy
{
    #[Decides(ClientPermission::ViewOwnProfile)]
    public function anyMethodName(User $user, Client $client): bool
    {
        return $client->getAttribute('owner_user_id') === $user->getKey();
    }
}
