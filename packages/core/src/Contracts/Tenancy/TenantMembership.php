<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Tenancy;

use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Tells whether a subject belongs to a tenant.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TenantMembership
{
    public function isMember(SubjectRef $subject, TenantRef $tenant): bool;
}
