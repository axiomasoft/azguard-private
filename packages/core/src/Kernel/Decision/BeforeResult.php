<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;

/**
 * A before hook may only let the check continue or deny it; it never grants.
 *
 * @closed
 */
#[Spi(kind: SpiKind::Call, since: '0.7')]
enum BeforeResult
{
    case Continue;
    case Deny;
}
