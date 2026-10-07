<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;

final class ContinueHook
{
    public function __invoke(AccessRequest $request, EvaluationContext $context): BeforeResult
    {
        return BeforeResult::Continue;
    }
}
