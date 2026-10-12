<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use RuntimeException;

/** A before hook that fails. */
final class ThrowingBeforeHook implements BeforeHook
{
    public function before(AccessRequest $request, EvaluationContext $context): BeforeResult
    {
        throw new RuntimeException('The ban list is unreachable.');
    }
}
