<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

/**
 * Why an assignment scope is evaluated: checking access, assigning, revoking or inspecting assignments.
 *
 * @api
 */
enum AssignmentScopePhase: string
{
    case Access = 'access';
    case Assignment = 'assignment';
    case Revocation = 'revocation';
    case Inspection = 'inspection';
}
