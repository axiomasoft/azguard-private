<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;

class PassRestriction implements Restriction
{
    public function key(): string
    {
        return static::class;
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
