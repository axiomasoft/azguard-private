<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;

final class ConfigCountingFilter implements AssignmentScopeFilter
{
    public static int $instances = 0;

    public function __construct()
    {
        self::$instances++;
    }

    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void {}
}
