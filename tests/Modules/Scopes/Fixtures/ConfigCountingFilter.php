<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Scopes\Fixtures;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
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
