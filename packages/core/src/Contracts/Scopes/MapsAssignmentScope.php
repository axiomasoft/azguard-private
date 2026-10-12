<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;

/**
 * A resource model that tells which assignment scope type it belongs to, so lists of visible resources can join it to
 * its context. `ContextAware` implements the methods.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MapsAssignmentScope
{
    public function azguardContextType(): string;

    /** Null means the model itself is the context. */
    public function azguardContextRelation(): ?string;
}
