<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Bare\Roles;

use AzGuard\Roles\BaseRole;

final class BareRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}
