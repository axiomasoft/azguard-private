<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Roles;

use AzGuard\Roles\BaseRole;
use Override;

final class SupportAdminRole extends BaseRole
{
    #[Override]
    public function getName(): string
    {
        return 'admin';
    }

    #[Override]
    public function permissions(): array
    {
        return [];
    }
}
