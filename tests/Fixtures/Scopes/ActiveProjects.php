<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ActiveProjects implements AssignmentScopeFilter
{
    /**
     * @param  Builder<Model>  $query
     */
    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        $query->where('is_active', true);
    }
}
