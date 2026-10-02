<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\AccessScope;

/**
 * A resource that reports its own tenant and assignment scope.
 *
 * @spi
 */
interface ProvidesAccessScope
{
    public function azguardScope(): AccessScope;
}
