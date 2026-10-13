<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Tenancy;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Directories\LookupContext;
use AzGuard\Directories\TenantOption;
use AzGuard\Kernel\Identity\TenantRef;

/**
 * Search of tenants for the interface. It does not decide membership.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TenantDirectory
{
    /**
     * @return list<TenantOption>
     */
    public function search(string $term, LookupContext $lookup, int $limit): array;

    public function describe(TenantRef $tenant, LookupContext $lookup): ?TenantOption;
}
