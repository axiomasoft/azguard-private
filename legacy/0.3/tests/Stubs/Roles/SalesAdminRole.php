<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Roles;

use AzGuard\Roles\BaseRole;
use Override;

final class SalesAdminRole extends BaseRole
{
    #[Override]
    public function getName(): string
    {
        return 'admin';
    }

    #[Override]
    public function getLevel(): int
    {
        return 7;
    }

    #[Override]
    public function permissions(): array
    {
        return [];
    }
}
