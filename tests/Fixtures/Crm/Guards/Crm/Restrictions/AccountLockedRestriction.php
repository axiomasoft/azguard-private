<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Restrictions;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Kernel\Decision\RoleContribution;

final class AccountLockedRestriction implements FiltersAccessQueries, Restriction
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

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        return $context->subjectModel()?->getAttribute('locked_at') === null ? P::pass() : P::deny();
    }
}
