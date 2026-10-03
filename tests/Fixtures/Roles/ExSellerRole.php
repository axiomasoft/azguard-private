<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Roles;

use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/**
 * Claims the key of `SellerRole` as its former key.
 */
#[Role('clerk')]
#[FormerKeys('seller')]
final class ExSellerRole extends BaseRole
{
    public function permissions(): array
    {
        return ['clients.view'];
    }
}
