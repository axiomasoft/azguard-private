<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves a one-off contextual permission check (workspace, tenant,
 * project…) without mutating the global authorization context.
 *
 * This contract lives in core, but is implemented and bound into the
 * container by the optional azguard/context package. Core depends only on
 * the contract: when the context package is absent the binding is simply
 * not registered, and contextual checks fall back to a global check (see
 * {@see HasPermissions}).
 *
 * @api
 */
interface ContextGuard
{
    /**
     * Check whether the user has the permission within the given context,
     * leaving any previously-set global context untouched.
     */
    public function checkInContext(
        Authenticatable $user,
        string $contextType,
        int|string $contextId,
        string $permission,
        string $panelId,
    ): bool;
}
