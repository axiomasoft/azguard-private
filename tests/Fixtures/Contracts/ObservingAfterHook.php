<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Contracts\Authorization\AfterHook;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;

/** An after hook that only counts the decisions it saw. */
class ObservingAfterHook implements AfterHook
{
    public static int $seen = 0;

    public function after(AccessRequest $request, EvaluationContext $context, Decision $decision): void
    {
        self::$seen++;
    }
}
