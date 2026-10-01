<?php

declare(strict_types=1);

namespace AzGuard\Events;

use AzGuard\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a role is detached from a model (usually a user).
 *
 * Listeners may flush permission caches, write an audit log, or notify.
 */
final readonly class RoleDetached
{
    use SerializesModels;

    public function __construct(
        public Model $model,
        public Role $role,
    ) {}
}
