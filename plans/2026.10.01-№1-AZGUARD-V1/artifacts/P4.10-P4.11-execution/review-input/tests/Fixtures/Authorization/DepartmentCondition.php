<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;

final class DepartmentCondition implements GrantCondition
{
    public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
    {
        return ($grant->fields()['department'] ?? null) === $context->subjectModel()?->getAttribute('department');
    }
}
