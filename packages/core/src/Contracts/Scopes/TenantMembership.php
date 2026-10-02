<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;

/**
 * Tells whether a subject belongs to a tenant.
 *
 * @spi
 */
interface TenantMembership
{
    public function isMember(SubjectRef $subject, TenantRef $tenant): bool;
}
