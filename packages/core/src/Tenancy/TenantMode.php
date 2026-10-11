<?php

declare(strict_types=1);

namespace AzGuard\Tenancy;

use AzGuard\Attributes\Api;

/**
 * Whether a panel isolates tenants: `None` accepts only global requests, `Required` requires a tenant on every request.
 *
 * @closed
 */
#[Api(since: '1.0')]
enum TenantMode: string
{
    case None = 'none';
    case Required = 'required';
}
