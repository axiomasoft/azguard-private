<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Internal;

/** @internal Optional read-routing signal for an evaluation context. */
interface PrimaryReadContext
{
    public function requiresPrimary(): bool;
}
