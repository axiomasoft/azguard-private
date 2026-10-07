<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Contracts\Scopes\TenantResolver;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Http\Request;

final class FixedTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?TenantRef
    {
        return null;
    }
}
