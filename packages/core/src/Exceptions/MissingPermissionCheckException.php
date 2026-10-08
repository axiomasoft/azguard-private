<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A route action of a panel in strict mode has no permission check and does not opt out of one.
 */
final class MissingPermissionCheckException extends ConfigurationException
{
    public function code(): string
    {
        return 'missing_permission_check';
    }
}
