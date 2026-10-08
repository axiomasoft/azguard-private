<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Tests\Fixtures\Contracts\GoodScopeResolver;
use Illuminate\Http\Request;

/** A scope resolver whose type has a colon. */
final class ColonScopeResolver extends GoodScopeResolver
{
    public function resolve(Request $request): ?AssignmentScopeRef
    {
        return AssignmentScopeRef::of('crm:store', 1);
    }
}
