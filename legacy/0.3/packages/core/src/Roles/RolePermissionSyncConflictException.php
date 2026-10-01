<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Exceptions\AzGuardException;

final class RolePermissionSyncConflictException extends AzGuardException
{
    public static function staleFingerprint(): self
    {
        return new self('Role permissions changed since this snapshot was taken. Reload and retry.');
    }
}
