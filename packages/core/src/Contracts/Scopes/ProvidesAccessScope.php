<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\AccessScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * A resource that reports its own tenant and assignment scope.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ProvidesAccessScope
{
    public function azguardScope(): AccessScope;
}
