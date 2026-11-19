<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * A source of code roles of a panel; it describes roles and never assigns them.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ProvidesRoles extends Source
{
    /**
     * Roles of the panel, read once while the panel is built.
     *
     * @return iterable<BaseRole>
     */
    public function roles(Panel $panel): iterable;
}
