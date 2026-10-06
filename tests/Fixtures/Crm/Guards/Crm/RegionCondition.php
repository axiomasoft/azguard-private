<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Tests\Fixtures\Crm\Models\Project;

final class RegionCondition implements FiltersAccessQueries, GrantCondition
{
    public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
    {
        $fields = $grant->fields();

        if (! array_key_exists('region', $fields) || $fields['region'] === null) {
            return true;
        }
        $project = Project::query()->find($context->scope()->context->id());

        return $fields['region'] === $project?->getAttribute('region') && ($fields['eligible'] ?? true) === true;
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        $fields = $contribution->fields();
        $predicate = ! isset($fields['region']) ? P::pass() : P::all(
            ($fields['eligible'] ?? true) === true ? P::pass() : P::deny(),
            P::exists('project', P::eq('region', $fields['region'])),
        );

        return P::branch($contribution, $predicate);
    }
}
