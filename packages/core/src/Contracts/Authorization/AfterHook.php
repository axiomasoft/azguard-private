<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Authorization;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * A hook that observes a finished decision; it cannot replace it.
 *
 * An exception is reported and the decision stays as it was.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AfterHook
{
    public function after(AccessRequest $request, EvaluationContext $context, Decision $decision): void;
}
