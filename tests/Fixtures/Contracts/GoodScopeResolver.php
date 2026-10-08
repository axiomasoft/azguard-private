<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Http\Request;

/** The store the user chose, from the `X-Store` header; none until the user chooses one. */
class GoodScopeResolver implements AssignmentScopeResolver
{
    public function resolve(Request $request): ?AssignmentScopeRef
    {
        $store = $request->header('X-Store');

        return is_string($store) && $store !== '' ? AssignmentScopeRef::of('store', $store) : null;
    }
}
