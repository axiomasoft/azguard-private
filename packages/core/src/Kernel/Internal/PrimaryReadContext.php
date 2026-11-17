<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Internal;

use Packman\Attributes\Internal;

/** @internal Optional read-routing signal for an evaluation context. */
#[Internal]
interface PrimaryReadContext
{
    public function requiresPrimary(): bool;
}
