<?php

declare(strict_types=1);

namespace AzGuard\Registry\Contracts;

use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A source of granted permissions for a user within a panel.
 *
 * Built-in sources: ClassRoleGrantSource, DatabaseRoleGrantSource and
 * DirectGrantSource. Implement this interface and register it (see
 * AzGuard::registerGrantSource()) to add your own. The context package layers
 * its workspace permissions on top via a PermissionLayer, not a GrantSource.
 *
 * @api
 */
interface GrantSource
{
    /**
     * Return the PermissionSet granted to the user in the given panel.
     */
    public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet;

    /**
     * Resolution order: sources are evaluated highest-priority first, which
     * lets a higher source short-circuit the chain by returning a wildcard.
     * It is NOT deny-precedence — the resolver merges (unions) all sources, so
     * a lower-priority source can never revoke a higher one's grants.
     *
     * Built-in sources return a {@see GrantPriority} value; custom sources may
     * return any int to slot between them (e.g. 85 to sit below DatabaseRole).
     */
    public function priority(): int;
}
