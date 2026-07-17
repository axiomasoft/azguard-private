<?php

declare(strict_types=1);

namespace AzGuard\Context\Events;

use AzGuard\Context\Models\ContextRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a context-scoped permission is granted to a user.
 *
 * Listeners may flush permission caches, write an audit log, or notify.
 */
final readonly class ContextGrantGiven
{
    use SerializesModels;

    public function __construct(
        public Authenticatable $user,
        public string $permissionKey,
        public string $panelId,
        public string $contextType,
        public int|string $contextId,
        public ContextRole $contextRole,
    ) {}
}
