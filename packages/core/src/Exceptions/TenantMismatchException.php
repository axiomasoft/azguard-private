<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The change addresses a tenant the panel or the assignment scope does not belong to.
 */
final class TenantMismatchException extends ChangeException
{
    public function code(): string
    {
        return 'tenant_mismatch';
    }
}
