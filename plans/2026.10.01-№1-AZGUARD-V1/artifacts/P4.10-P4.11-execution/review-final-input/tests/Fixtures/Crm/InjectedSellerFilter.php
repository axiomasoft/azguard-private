<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class InjectedSellerFilter implements AssignmentScopeFilter
{
    public static int $constructed = 0;

    public static array $observed = [];

    public function __construct(private readonly CityDirectory $directory)
    {
        self::$constructed++;
    }

    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        self::$observed[] = $runtime;

        if (! $runtime->user instanceof User) {
            throw new RuntimeException('Target User required');
        }
        $query->where('city_id', $this->directory->city($runtime->user));
    }
}
