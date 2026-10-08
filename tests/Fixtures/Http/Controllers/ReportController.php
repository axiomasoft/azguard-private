<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http\Controllers;

use AzGuard\Attributes\CheckPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;

/** Inherits the check of its parent and adds its own on one action. */
final class ReportController extends CrmController
{
    public function index(): string
    {
        return 'reports';
    }

    #[CheckPermission('clients.update', on: 'client')]
    public function rebuild(Client $client): string
    {
        return 'rebuilt '.$client->getKey();
    }
}
