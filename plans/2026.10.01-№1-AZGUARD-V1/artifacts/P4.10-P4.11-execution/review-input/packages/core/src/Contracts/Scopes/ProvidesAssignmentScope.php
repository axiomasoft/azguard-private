<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\AssignmentScopeRef;

/**
 * A resource that reports its assignment scope in a panel without tenants.
 *
 * @spi
 */
interface ProvidesAssignmentScope
{
    public function azguardAssignmentScope(): ?AssignmentScopeRef;
}
