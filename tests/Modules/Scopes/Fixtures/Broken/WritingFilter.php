<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Fixtures\Broken;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class WritingFilter implements AssignmentScopeFilter
{
    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        DB::table('projects')->where('id', 0)->update(['city' => 'west']);
        $query->where('is_active', true);
    }
}
