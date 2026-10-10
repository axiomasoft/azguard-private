<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Authorization;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;

/**
 * Qualifies one contribution before contributions are joined with OR.
 *
 * @spi
 */
interface GrantCondition
{
    public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool;
}
