<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Tests\Fixtures\Crm\Models\User;

final class CityDirectory
{
    public function city(User $user): int
    {
        return (int) $user->getAttribute('city_id');
    }
}
