<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Schema;

use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;

/** A typed filter with a constructor argument: a schema names its class, never the argument. */
final readonly class RegionProjects implements AssignmentScopeFilter
{
    public function __construct(private string $region) {}

    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        $query->where('region', $this->region);
    }
}
