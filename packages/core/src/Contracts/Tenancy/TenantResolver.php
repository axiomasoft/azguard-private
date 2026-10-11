<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Tenancy;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Http\Request;

/**
 * Selects the current tenant from an HTTP request.
 */
#[Spi(kind: SpiKind::Implement, since: '0.7')]
interface TenantResolver
{
    public function resolve(Request $request): ?TenantRef;
}
