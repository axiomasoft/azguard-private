<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Http\Request;

final class FixedScopeResolver implements AssignmentScopeResolver
{
    public function resolve(Request $request): ?AssignmentScopeRef
    {
        return null;
    }
}
