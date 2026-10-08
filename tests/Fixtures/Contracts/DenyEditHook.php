<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;

/** A before hook that stops every permission that edits. */
class DenyEditHook
{
    public function __invoke(AccessRequest $request): BeforeResult
    {
        return str_ends_with($request->permission()->local(), '.edit') ? BeforeResult::Deny : BeforeResult::Continue;
    }
}
