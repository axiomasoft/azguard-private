<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Database;

use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use RuntimeException;

final class FencedDefinitionsOnly implements FencesReads, ProvidesPermissions
{
    public int $stateCalls = 0;

    public function id(): string
    {
        return 'definitions-only';
    }

    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        return [];
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function state(Panel $panel, TenantRef $tenant): StateToken
    {
        $this->stateCalls++;

        throw new RuntimeException('Unconsumed definition authority must never be read.');
    }
}
