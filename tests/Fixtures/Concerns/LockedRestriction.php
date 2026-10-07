<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Tests\Fixtures\Authorization\PassRestriction;

/** Denies every check of the panel: a permission set still lists what the grants assign. */
final class LockedRestriction extends PassRestriction
{
    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        return RestrictionResult::deny('locked');
    }
}
