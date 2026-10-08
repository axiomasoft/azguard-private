<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http;

use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Http\Request;

/** The project the user chose, from the `X-Project` header; none until the user chooses one. */
final class HeaderProjectResolver implements AssignmentScopeResolver
{
    public function resolve(Request $request): ?AssignmentScopeRef
    {
        $project = $request->header('X-Project');

        return is_string($project) && $project !== '' ? AssignmentScopeRef::of('crm.project', $project) : null;
    }
}
