<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Fixtures;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

#[Role('reader')]
final class WideReaderRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.view'];
    }
}
