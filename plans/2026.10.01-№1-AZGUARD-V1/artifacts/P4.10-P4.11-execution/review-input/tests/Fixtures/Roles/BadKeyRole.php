<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Roles;

use AzGuard\Roles\BaseRole;

/**
 * Overrides key() with a key the grammar rejects; the attribute check does not run for it.
 */
final class BadKeyRole extends BaseRole
{
    public function key(): string
    {
        return 'Bad Key';
    }

    public function permissions(): array
    {
        return [];
    }
}
