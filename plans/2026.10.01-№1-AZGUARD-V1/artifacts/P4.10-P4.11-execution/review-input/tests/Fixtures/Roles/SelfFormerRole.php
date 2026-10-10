<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Roles;

use AzGuard\Roles\BaseRole;

/**
 * Overrides formerKeys() with its own current key.
 */
final class SelfFormerRole extends BaseRole
{
    public function key(): string
    {
        return 'auditor';
    }

    public function formerKeys(): array
    {
        return ['inspector', 'auditor'];
    }

    public function permissions(): array
    {
        return [];
    }
}
