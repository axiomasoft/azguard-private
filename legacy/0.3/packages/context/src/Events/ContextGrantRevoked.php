<?php

declare(strict_types=1);

namespace AzGuard\Context\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a context-scoped permission is revoked from a user.
 *
 * permissionKey is '*' when every permission for the context was cleared
 * via ContextGrantBuilder::revokeAll(). Listeners may flush permission
 * caches, write an audit log, or notify.
 */
final readonly class ContextGrantRevoked
{
    use SerializesModels;

    public function __construct(
        public Authenticatable $user,
        public string $permissionKey,
        public string $panelId,
        public string $contextType,
        public int|string $contextId,
    ) {}
}
