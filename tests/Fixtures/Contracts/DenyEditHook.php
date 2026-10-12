<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;

/** A before hook that stops every permission that edits. */
class DenyEditHook implements BeforeHook
{
    public function before(AccessRequest $request, EvaluationContext $context): BeforeResult
    {
        return str_ends_with($request->permission()->local(), '.edit') ? BeforeResult::Deny : BeforeResult::Continue;
    }
}
