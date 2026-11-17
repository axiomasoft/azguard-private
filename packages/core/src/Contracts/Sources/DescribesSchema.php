<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Describes what the source can contribute to the schema of a panel.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DescribesSchema extends Source
{
    public function describe(Panel $panel, ?TenantRef $tenant = null): SourceDescription;
}
