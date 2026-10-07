<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Values of one decision contradict each other: a reason the effect cannot carry, or different state tokens in one set.
 */
final class ConsistencyException extends AuthorizationEngineException
{
    public function code(): string
    {
        return 'consistency';
    }
}
