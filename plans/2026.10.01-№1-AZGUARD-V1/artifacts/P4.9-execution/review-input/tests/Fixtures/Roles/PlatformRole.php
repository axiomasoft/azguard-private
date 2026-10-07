<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Roles;

use AzGuard\Roles\BaseRole;

/**
 * `RootRole` flags declared by methods; no label, so the label falls back to the key.
 */
final class PlatformRole extends BaseRole
{
    public function key(): string
    {
        return 'platform';
    }

    public function superAdmin(): bool
    {
        return true;
    }

    public function grantable(): bool
    {
        return false;
    }

    public function permissions(): array
    {
        return [];
    }
}
