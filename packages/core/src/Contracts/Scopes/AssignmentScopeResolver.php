<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Http\Request;

/**
 * Selects the current assignment scope from an HTTP request.
 */
#[Spi(kind: SpiKind::Implement, since: '0.7')]
interface AssignmentScopeResolver
{
    public function resolve(Request $request): ?AssignmentScopeRef;
}
