<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Http\Request;

/**
 * Selects the current assignment scope from an HTTP request.
 *
 * @spi
 */
interface AssignmentScopeResolver
{
    public function resolve(Request $request): ?AssignmentScopeRef;
}
