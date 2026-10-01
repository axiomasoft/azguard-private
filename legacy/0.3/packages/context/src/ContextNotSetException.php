<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Exceptions\AzGuardException;

/**
 * Thrown by {@see ContextGrantBuilder} when a mutating/reading method is
 * called before ->inContext(contextType, contextId) was set.
 */
final class ContextNotSetException extends AzGuardException
{
    public function __construct()
    {
        parent::__construct(
            'No context provided. Call ->inContext("workspace", $id) before '
            .'grant()/revoke()/revokeAll()/grants().',
        );
    }
}
