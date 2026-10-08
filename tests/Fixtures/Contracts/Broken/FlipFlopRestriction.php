<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;

/** A restriction that denies every other request. */
final class FlipFlopRestriction extends BaseRestriction
{
    private int $calls = 0;

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        return $this->calls++ % 2 === 0 ? RestrictionResult::deny('flip') : RestrictionResult::pass();
    }
}
