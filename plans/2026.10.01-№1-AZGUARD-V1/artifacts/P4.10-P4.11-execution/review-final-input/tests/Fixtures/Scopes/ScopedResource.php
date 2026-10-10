<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Kernel\Identity\AccessScope;

final readonly class ScopedResource implements ProvidesAccessScope
{
    public function __construct(private readonly AccessScope $owner) {}

    public function azguardScope(): AccessScope
    {
        return $this->owner;
    }
}
