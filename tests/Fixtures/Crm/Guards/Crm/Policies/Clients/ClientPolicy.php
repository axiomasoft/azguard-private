<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Policies\Clients;

use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Auth\Access\Response;

final class ClientPolicy
{
    public static bool $override = false;

    public static bool|Response|null $result = true;

    #[Decides(ClientPermission::View)]
    public function view(User $user, Client $client): bool|Response|null
    {
        return self::$override ? self::$result : true;
    }

    #[Decides(ClientPermission::Update)]
    public function update(User $user, Client $client): bool|Response|null
    {
        return self::$override ? self::$result : ! $client->getAttribute('do_not_call');
    }

    #[Decides(ClientPermission::ViewOwnProfile)]
    public function own(User $user, Client $client): bool|Response|null
    {
        return self::$override ? self::$result : $client->getAttribute('owner_user_id') === $user->getKey();
    }
}
