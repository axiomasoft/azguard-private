<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * Values of one decision contradict each other: a reason the effect cannot carry, or different state tokens in one set.
 */
#[Api(since: '0.7')]
final class ConsistencyException extends AuthorizationEngineException
{
    public function code(): string
    {
        return 'consistency';
    }
}
