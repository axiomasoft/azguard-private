<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\AssignmentScopeRef;

/**
 * A resource that reports its assignment scope in a panel without tenants.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ProvidesAssignmentScope
{
    public function azguardAssignmentScope(): ?AssignmentScopeRef;
}
