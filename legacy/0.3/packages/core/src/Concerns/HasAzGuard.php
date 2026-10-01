<?php

declare(strict_types=1);

namespace AzGuard\Concerns;

/**
 * Convenience trait that composes the full AzGuard user API.
 *
 * Add this single trait to your User model to get role checks
 * ({@see HasRoles}) and permission checks ({@see HasPermissions}):
 *
 *   $user->assignRole('editor');
 *   $user->hasRole('editor');
 *   $user->hasPermission('app.posts.edit');
 *   $user->hasPermissionIn('workspace', 42, 'app.posts.edit');
 *   $user->permissions('app');           // Collection<int, string>
 *   $user->flushPermissions();
 *
 * Use {@see HasRoles} or {@see HasPermissions} directly if you only need
 * a subset, and add {@see HasDirectGrants} / {@see HasScopedRoles} when you
 * need one-off grants or entity-scoped roles.
 */
trait HasAzGuard
{
    use HasPermissions;
    use HasRoles;
}
