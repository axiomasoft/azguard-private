<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Changes;

use Packman\Attributes\Api;

/**
 * The change addresses a tenant the panel or the assignment scope does not belong to.
 */
#[Api(since: '1.0')]
final class TenantMismatchException extends ChangeException
{
    public function code(): string
    {
        return 'tenant_mismatch';
    }
}
