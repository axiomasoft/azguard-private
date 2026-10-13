<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Fixtures\Broken;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use Illuminate\Database\Eloquent\Builder;

final class DeletingFilter implements AssignmentScopeFilter
{
    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        $query->where('is_active', true)->delete();
    }
}
