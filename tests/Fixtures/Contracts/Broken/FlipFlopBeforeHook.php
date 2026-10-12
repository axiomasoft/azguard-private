<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;

/** A before hook that denies every other request. */
final class FlipFlopBeforeHook implements BeforeHook
{
    public static int $calls = 0;

    public function before(AccessRequest $request, EvaluationContext $context): BeforeResult
    {
        return self::$calls++ % 2 === 0 ? BeforeResult::Deny : BeforeResult::Continue;
    }
}
