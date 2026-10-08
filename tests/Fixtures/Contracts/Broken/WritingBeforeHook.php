<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;

/** A before hook that counts the checks in the database. */
final class WritingBeforeHook
{
    public function __invoke(AccessRequest $request): BeforeResult
    {
        app('db')->table('contract_probe')->insert(['note' => $request->permission()->full()]);

        return BeforeResult::Continue;
    }
}
