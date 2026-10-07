<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;

/**
 * Revision of a consumed authority snapshot.
 *
 * @spi
 */
interface FencesReads extends Source
{
    public function state(Panel $panel, TenantRef $tenant): StateToken;
}
