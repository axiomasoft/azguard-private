<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A grant, role contribution or restriction result supplied by an extension breaks the decision contract.
 */
final class InvalidSourceContributionException extends AuthorizationEngineException
{
    public function code(): string
    {
        return 'invalid_source_contribution';
    }
}
