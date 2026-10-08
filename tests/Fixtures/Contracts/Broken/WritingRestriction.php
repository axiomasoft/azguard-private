<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;

/** A restriction that records every check in the database. */
final class WritingRestriction extends BaseRestriction
{
    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        app('db')->table('contract_probe')->insert(['note' => $request->permission()->full()]);

        return RestrictionResult::pass();
    }
}
