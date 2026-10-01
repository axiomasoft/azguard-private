<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;

/** @api */
interface PermissionResolverInterface
{
    public function forUser(Authenticatable $user, string $panelId): PermissionSet;

    /**
     * Durable invalidation: bumps the per-user+panel epoch (on a persistent
     * store), orphaning every context-discriminated cache entry. Use this for
     * real grant/role changes only — DirectGrant save/delete, role
     * attach/detach, HasPermissions mutation. See forgetRequestCache() for the
     * transient, non-durable counterpart.
     */
    public function forgetForUser(Authenticatable $user, string $panelId): void;

    /**
     * In-process-only invalidation: drops the request-local cached
     * PermissionSet(s) for this user+panel WITHOUT bumping the durable
     * per-user epoch. Use this for transient, within-request context
     * switches (e.g. ContextGuard::checkInContext) where no real grant/role
     * change occurred and a cross-request cache must NOT be busted.
     */
    public function forgetRequestCache(Authenticatable $user, string $panelId): void;
}
