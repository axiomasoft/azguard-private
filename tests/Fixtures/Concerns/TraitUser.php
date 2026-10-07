<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Concerns\HasAzGuard;
use AzGuard\Tests\Fixtures\Panels\User;

/** The `User` of the panel selection matrix with the trait. */
class TraitUser extends User
{
    use HasAzGuard;

    public function getMorphClass(): string
    {
        return 'trait-user';
    }
}
