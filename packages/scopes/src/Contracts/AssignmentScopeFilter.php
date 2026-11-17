<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Contracts;

use AzGuard\Context\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * A predicate added to the structural query of an assignment scope. It does not choose the owner.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AssignmentScopeFilter
{
    /**
     * @param  Builder<Model>  $query
     */
    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void;
}
