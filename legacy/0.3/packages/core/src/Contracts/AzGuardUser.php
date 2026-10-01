<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * The canonical type for "a user that speaks AzGuard".
 *
 * Composes the permission and role contracts (mirroring the
 * {@see HasAzGuard} trait) and Laravel's {@see Authorizable}
 * so `$user->can()` and `$user->hasPermission()` are both type-safe. Declare it
 * on your User model and add the trait — the trait already provides every method:
 *
 *   use AzGuard\Contracts\AzGuardUser;
 *   use AzGuard\Concerns\HasAzGuard;
 *
 *   class User extends Authenticatable implements AzGuardUser
 *   {
 *       use HasAzGuard;
 *   }
 *
 * Add {@see HasScopedRoles} / {@see HasDirectGrants} (contract + matching trait)
 * when you opt into entity-scoped roles or direct grants.
 *
 * @api
 */
interface AzGuardUser extends Authorizable, HasPermissions, HasRoles {}
