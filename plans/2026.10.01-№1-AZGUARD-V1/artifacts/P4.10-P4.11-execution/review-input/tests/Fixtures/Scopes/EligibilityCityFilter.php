<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;

final class EligibilityCityFilter implements AssignmentScopeFilter
{
    public static array $observed = [];

    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        self::$observed[] = $runtime;
        $city = $runtime->user?->getAttribute('city');
        $fields = $runtime->grant?->fields() ?? [];

        if (! is_string($city) || ($fields['eligible'] ?? true) === false) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where('city', $city);
    }
}
