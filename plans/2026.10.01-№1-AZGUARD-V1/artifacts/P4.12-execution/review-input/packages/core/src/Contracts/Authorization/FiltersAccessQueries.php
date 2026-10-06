<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Authorization;

use AzGuard\Kernel\Decision\AccessPredicate;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;

/**
 * Exact scalar/query equivalence for one permission, resource type and context.
 * Policy adapters assert disjoint, exhaustive allow/deny/abstain partitions;
 * before hooks assert deny/pass partitions and never create authority.
 * Restrictions and conditions return boolean trees. Conditions require the
 * concrete contribution; all its qualifiers belong to the same bound branch.
 * Return unsupported() when equivalence cannot be expressed. NULL SQL must be
 * assigned an explicit outcome; a SQL NULL is not implicitly policy abstention.
 *
 * @spi
 */
interface FiltersAccessQueries
{
    public function predicate(
        AccessRequest $request,
        string $resourceType,
        EvaluationContext $context,
        Grant|RoleContribution|null $contribution = null,
    ): AccessPredicate;
}
