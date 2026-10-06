<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Restrictions;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;

final class AccountLockedRestriction implements Restriction
{
    public function key(): string
    {
        return 'account_locked';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return true;
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        return $context->subjectModel()?->getAttribute('locked_at') === null ? RestrictionResult::pass() : RestrictionResult::deny('account_locked');
    }

    public function exemptsSuperAdmin(): bool
    {
        return false;
    }
}
