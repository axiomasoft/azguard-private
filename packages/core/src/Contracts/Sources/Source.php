<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;

/**
 * A source of permission definitions and grants attached to a panel; what it provides is declared by the
 * capability interfaces it implements.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface Source
{
    /**
     * Stable id of the source inside a panel; two sources of one panel never share it.
     */
    public function id(): string;
}
