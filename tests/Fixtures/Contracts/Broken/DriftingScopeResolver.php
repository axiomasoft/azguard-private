<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Tests\Fixtures\Contracts\GoodScopeResolver;
use Illuminate\Http\Request;

/** A scope resolver that picks another store at every call. */
final class DriftingScopeResolver extends GoodScopeResolver
{
    private int $calls = 0;

    public function resolve(Request $request): ?AssignmentScopeRef
    {
        return AssignmentScopeRef::of('store', 1 + $this->calls++);
    }
}
