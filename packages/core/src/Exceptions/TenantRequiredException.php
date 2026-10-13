<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The panel requires a tenant for this change.
 */
#[Api(since: '1.0')]
final class TenantRequiredException extends ChangeException
{
    public function code(): string
    {
        return 'tenant_required';
    }
}
