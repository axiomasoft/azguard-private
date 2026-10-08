<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http\Controllers;

use AzGuard\Attributes\CheckPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;

/** Checks on the actions, with the client of the route as the resource. */
final class ClientController
{
    #[CheckPermission('clients.update', on: 'client')]
    public function update(Client $client): string
    {
        return 'updated '.$client->getKey();
    }

    #[CheckPermission('clients.view', on: 'client', status: 404, message: 'No such client.')]
    public function show(Client $client): string
    {
        return 'client '.$client->getKey();
    }
}
