<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Decision\AccessRequest;

/** A before hook that answers with a boolean instead of a BeforeResult. */
final class BooleanBeforeHook
{
    public function __invoke(AccessRequest $request): bool
    {
        return true;
    }
}
