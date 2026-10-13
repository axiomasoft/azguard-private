<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\AccessScope;

/**
 * A resource that reports its own tenant and assignment scope.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ProvidesAccessScope
{
    public function azguardScope(): AccessScope;
}
