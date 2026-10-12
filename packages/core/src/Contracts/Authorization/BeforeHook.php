<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Authorization;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;

/**
 * A hook that runs before the sources and may only let the check continue or deny it; it never grants.
 *
 * An exception denies the check as `hook_error`. SQL visibility needs `FiltersAccessQueries` on the same object.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface BeforeHook
{
    public function before(AccessRequest $request, EvaluationContext $context): BeforeResult;
}
