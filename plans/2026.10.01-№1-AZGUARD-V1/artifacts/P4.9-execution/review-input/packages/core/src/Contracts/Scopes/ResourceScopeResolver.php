<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\AccessScope;

/**
 * Derives the tenant and assignment scope a resource belongs to.
 *
 * @spi
 */
interface ResourceScopeResolver
{
    public function resolve(object $resource, ?AccessScope $selected = null): AccessScope;
}
