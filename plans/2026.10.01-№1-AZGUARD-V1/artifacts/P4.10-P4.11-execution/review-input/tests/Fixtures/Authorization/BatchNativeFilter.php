<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class BatchNativeFilter implements AssignmentScopeFilter
{
    public static array $fields = [];

    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        if ($runtime->subject->id() === '2') {
            throw new RuntimeException('Only the second subject native predicate is unavailable.');
        }
        $eligible = $runtime->grant?->fields()['eligible'] ?? true;
        self::$fields[] = $eligible;
        $query->where('is_active', true);

        if (! $eligible) {
            $query->where('id', 0);
        }
    }
}
