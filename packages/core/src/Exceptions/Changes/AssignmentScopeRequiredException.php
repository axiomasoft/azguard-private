<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Changes;

use Packman\Attributes\Api;

/**
 * The role is granted only inside an assignment scope.
 */
#[Api(since: '1.0')]
final class AssignmentScopeRequiredException extends ChangeException
{
    public function code(): string
    {
        return 'context_required';
    }
}
