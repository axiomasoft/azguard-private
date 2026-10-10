<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Admin\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;

final class ProjectScope implements AssignmentScopeDefinition
{
    public function type(): string
    {
        return 'orders.project';
    }

    public function model(): ?string
    {
        return null;
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        return null;
    }
}
