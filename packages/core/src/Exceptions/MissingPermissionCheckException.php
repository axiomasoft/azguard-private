<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * A route action of a panel in strict mode has no permission check and does not opt out of one.
 */
#[Api(since: '0.7')]
final class MissingPermissionCheckException extends ConfigurationException
{
    public function code(): string
    {
        return 'missing_permission_check';
    }
}
