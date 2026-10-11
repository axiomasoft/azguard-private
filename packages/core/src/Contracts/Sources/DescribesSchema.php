<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;

/**
 * Describes what the source can contribute to the schema of a panel.
 */
#[Spi(kind: SpiKind::Implement, since: '0.7')]
interface DescribesSchema extends Source
{
    public function describe(Panel $panel, ?TenantRef $tenant = null): SourceDescription;
}
