<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Fixtures\Broken;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use Illuminate\Database\Eloquent\Builder;

final class DriftingFilter implements AssignmentScopeFilter
{
    public static int $calls = 0;

    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        $query->where('id', self::$calls++ === 0 ? '>' : '<', 2);
    }
}
