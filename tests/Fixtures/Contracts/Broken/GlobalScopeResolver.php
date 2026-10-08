<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Tests\Fixtures\Contracts\GoodScopeResolver;
use Illuminate\Http\Request;

/** A scope resolver that answers the global scope when the request names none. */
final class GlobalScopeResolver extends GoodScopeResolver
{
    public function resolve(Request $request): ?AssignmentScopeRef
    {
        return parent::resolve($request) ?? AssignmentScopeRef::global();
    }
}
