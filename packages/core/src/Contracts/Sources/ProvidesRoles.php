<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;

/**
 * A source of code roles of a panel; it describes roles and never assigns them.
 */
#[Spi(kind: SpiKind::Implement, since: '0.7')]
interface ProvidesRoles extends Source
{
    /**
     * Roles of the panel, read once while the panel is built.
     *
     * @return iterable<BaseRole>
     */
    public function roles(Panel $panel): iterable;
}
