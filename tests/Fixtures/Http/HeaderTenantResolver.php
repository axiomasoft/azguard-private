<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http;

use AzGuard\Contracts\Scopes\TenantResolver;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Http\Request;

/** The organization of the request from the `X-Tenant` header, as an application tenant middleware would pick it. */
final class HeaderTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?TenantRef
    {
        $tenant = $request->header('X-Tenant');

        return is_string($tenant) && $tenant !== '' ? TenantRef::of('crm.organization', $tenant) : null;
    }
}
