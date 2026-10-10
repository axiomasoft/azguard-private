<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Relation;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;

final class QuerylessDefinition implements AssignmentScopeDefinition
{
    public function type(): string
    {
        return 'external';
    }

    public function model(): string
    {
        return Project::class;
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        return null;
    }
}
