<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Panels\Panel;
use AzGuard\Policies\PolicyBinding;

/**
 * A source of policy bindings: the only authority of a policy-only permission, a veto for a grants permission.
 *
 * @spi
 */
interface ProvidesPolicies extends Source
{
    /**
     * Bindings of permissions of the panel to policies, read once while the panel is built.
     *
     * @return iterable<PolicyBinding>
     */
    public function policies(Panel $panel): iterable;
}
