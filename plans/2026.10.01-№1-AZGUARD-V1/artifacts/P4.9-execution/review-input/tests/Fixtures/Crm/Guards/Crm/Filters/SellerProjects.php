<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters;

use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;

final class SellerProjects implements AssignmentScopeFilter
{
    /** @var list<AssignmentScopeRuntime> */
    public static array $observed = [];

    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        self::$observed[] = $runtime;
        $query->where('city_id', $runtime->user?->getAttribute('city_id'));
    }
}
