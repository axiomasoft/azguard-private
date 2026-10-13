<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Attributes\Api;

/**
 * @closed
 */
#[Api(since: '1.0')]
enum Effect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case NotApplicable = 'not_applicable';
}
