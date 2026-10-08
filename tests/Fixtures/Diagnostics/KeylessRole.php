<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Diagnostics;

use AzGuard\Roles\BaseRole;

/** A role without #[Role] or key(): it has no stable key. */
final class KeylessRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}
