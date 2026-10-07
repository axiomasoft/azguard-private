<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The panel requires a tenant for this change.
 */
final class TenantRequiredException extends ChangeException
{
    public function code(): string
    {
        return 'tenant_required';
    }
}
