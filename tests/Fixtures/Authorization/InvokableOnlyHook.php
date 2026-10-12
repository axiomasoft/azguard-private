<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;

/** An invokable class that does not implement `BeforeHook`: the slot no longer accepts it by `__invoke()`. */
final class InvokableOnlyHook
{
    public function __invoke(AccessRequest $request, EvaluationContext $context): BeforeResult
    {
        return BeforeResult::Continue;
    }
}
