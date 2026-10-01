<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Optional post-aggregation hook applied by EffectivePermissionResolver after
 * it has unioned every GrantSource into the global PermissionSet.
 *
 * Unlike a GrantSource (which can only add permissions), a layer receives the
 * fully-resolved global set and may return a different set — so it can also
 * restrict, e.g. the context package narrowing the result to the active
 * workspace. Bind a single implementation to this interface to install it; with
 * none bound the resolver leaves the global set untouched.
 *
 * @api
 */
interface PermissionLayer
{
    public function apply(PermissionSet $global, Authenticatable $user, string $panelId): PermissionSet;

    /**
     * A string distinguishing cache entries when the layer's output depends on
     * out-of-band state (e.g. the active workspace context). Returned by the
     * resolver into the permission cache key so that, for example, workspace 42
     * and workspace 99 never share a cached set. Return '' when the layer adds
     * no cache-relevant state for this panel.
     */
    public function cacheDiscriminator(string $panelId): string;
}
