<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Attributes\Api;

/**
 * How a panel treats assignment scopes: `None` accepts only global requests, `Inherit` and `Isolated` accept the
 * declared scope types (tenant-wide grants apply inside a scope only under `Inherit`), `Required` requires a scope on
 * every request.
 *
 * @closed
 */
#[Api(since: '1.0')]
enum AssignmentScopeMode: string
{
    case None = 'none';
    case Inherit = 'inherit';
    case Isolated = 'isolated';
    case Required = 'required';
}
