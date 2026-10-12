<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\AfterHook;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;

/** An after hook that writes a row for every decision. */
final class WritingAfterHook implements AfterHook
{
    public function after(AccessRequest $request, EvaluationContext $context, Decision $decision): void
    {
        app('db')->table('contract_probe')->insert(['note' => $request->permission()->full()]);
    }
}
