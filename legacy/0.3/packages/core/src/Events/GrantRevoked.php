<?php

declare(strict_types=1);

namespace AzGuard\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a direct grant is revoked.
 *
 * permissionKey is '*' when the whole panel was cleared via revokeAll().
 * Listeners may flush permission caches, write an audit log, or notify.
 */
final readonly class GrantRevoked
{
    use SerializesModels;

    public function __construct(
        public Authenticatable $user,
        public string $permissionKey,
        public string $panelId,
    ) {}
}
