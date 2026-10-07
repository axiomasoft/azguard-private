<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\User;

final class UnattributedClientPolicy
{
    public function anyMethodName(User $user, Client $client): bool
    {
        return true;
    }
}
