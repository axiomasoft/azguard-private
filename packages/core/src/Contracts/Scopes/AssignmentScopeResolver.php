<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Http\Request;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Selects the current assignment scope from an HTTP request.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AssignmentScopeResolver
{
    public function resolve(Request $request): ?AssignmentScopeRef;
}
