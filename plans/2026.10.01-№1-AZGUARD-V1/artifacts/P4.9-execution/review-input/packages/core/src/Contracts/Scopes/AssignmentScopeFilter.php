<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Scopes\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A predicate added to the structural query of an assignment scope. It does not choose the owner.
 *
 * @spi
 */
interface AssignmentScopeFilter
{
    /**
     * @param  Builder<Model>  $query
     */
    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void;
}
