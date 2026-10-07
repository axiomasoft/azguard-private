<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Authorization;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;

/**
 * A constraint on an authority candidate; it never grants access.
 *
 * Exact list queries evaluate applicability without a resource. A restriction that
 * implements FiltersAccessQueries is always compiled through predicate(), so it must
 * encode its own applicability there (AccessPredicate::pass() when inapplicable) and
 * appliesTo() is not consulted for lists. For a restriction without that adapter
 * appliesTo() may skip it only when the answer does not depend on the resource.
 *
 * @spi
 */
interface Restriction
{
    public function key(): string;

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool;

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult;

    public function exemptsSuperAdmin(): bool;
}
