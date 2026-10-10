<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Roles;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

#[Role('from-attribute', label: 'Attribute', level: 1)]
final class OverrideRole extends BaseRole
{
    public function key(): string
    {
        return 'from-method';
    }

    public function label(): string
    {
        return 'Method';
    }

    public function level(): int
    {
        return 4;
    }

    public function permissions(): array
    {
        return [];
    }
}
