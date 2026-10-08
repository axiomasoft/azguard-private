<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use RuntimeException;

/** A before hook that fails. */
final class ThrowingBeforeHook
{
    public function __invoke(AccessRequest $request): BeforeResult
    {
        throw new RuntimeException('The ban list is unreachable.');
    }
}
