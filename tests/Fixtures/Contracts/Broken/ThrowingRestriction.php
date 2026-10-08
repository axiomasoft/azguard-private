<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;
use RuntimeException;

/** A restriction that fails on an ordinary request. */
final class ThrowingRestriction extends BaseRestriction
{
    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        throw new RuntimeException('The directory is unreachable.');
    }
}
