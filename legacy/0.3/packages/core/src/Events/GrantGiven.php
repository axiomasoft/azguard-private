<?php

declare(strict_types=1);

namespace AzGuard\Events;

use AzGuard\Models\DirectGrant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a direct grant is issued to a user.
 *
 * Listeners may flush permission caches, write an audit log, or notify.
 */
final readonly class GrantGiven
{
    use SerializesModels;

    public function __construct(
        public Authenticatable $user,
        public string $permissionKey,
        public string $panelId,
        public DirectGrant $grant,
    ) {}
}
