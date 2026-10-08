<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;

abstract class BaseRestriction implements Restriction
{
    public function key(): string
    {
        return 'broken';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return true;
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        return RestrictionResult::pass();
    }

    public function exemptsSuperAdmin(): bool
    {
        return false;
    }
}
