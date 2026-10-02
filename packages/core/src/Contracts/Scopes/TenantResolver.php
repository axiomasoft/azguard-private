<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Http\Request;

/**
 * Selects the current tenant from an HTTP request.
 *
 * @spi
 */
interface TenantResolver
{
    public function resolve(Request $request): ?TenantRef;
}
