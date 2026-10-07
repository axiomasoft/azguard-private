<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Authorization;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\RestrictionResult;

/**
 * A constraint on an authority candidate; it never grants access.
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
