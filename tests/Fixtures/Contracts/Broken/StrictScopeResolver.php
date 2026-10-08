<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Tests\Fixtures\Contracts\GoodScopeResolver;
use Illuminate\Http\Request;
use RuntimeException;

/** A scope resolver that fails when the request names no scope. */
final class StrictScopeResolver extends GoodScopeResolver
{
    public function resolve(Request $request): ?AssignmentScopeRef
    {
        return parent::resolve($request) ?? throw new RuntimeException('X-Store is required.');
    }
}
