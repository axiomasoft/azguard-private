<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Authorization;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Qualifies one contribution before contributions are joined with OR.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface GrantCondition
{
    public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool;
}
