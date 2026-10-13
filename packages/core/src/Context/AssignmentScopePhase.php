<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Attributes\Api;

/**
 * Why an assignment scope is evaluated: checking access, assigning, revoking or inspecting assignments.
 *
 * @closed
 */
#[Api(since: '1.0')]
enum AssignmentScopePhase: string
{
    case Access = 'access';
    case Assignment = 'assignment';
    case Revocation = 'revocation';
    case Inspection = 'inspection';
}
