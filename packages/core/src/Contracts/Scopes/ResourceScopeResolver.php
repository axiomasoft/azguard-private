<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\AccessScope;

/**
 * Derives the tenant and assignment scope a resource belongs to.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ResourceScopeResolver
{
    public function resolve(object $resource, ?AccessScope $selected = null): AccessScope;
}
