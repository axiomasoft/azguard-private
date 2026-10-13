<?php

declare(strict_types=1);

namespace Acme\Consumer;

use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;

/** The consumer's before hook: stops every permission that edits, continues the rest. */
final class ConsumerBeforeHook implements BeforeHook
{
    public function before(AccessRequest $request, EvaluationContext $context): BeforeResult
    {
        return str_ends_with($request->permission()->local(), '.edit') ? BeforeResult::Deny : BeforeResult::Continue;
    }
}
