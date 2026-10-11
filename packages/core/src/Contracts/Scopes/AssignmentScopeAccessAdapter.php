<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides which assignment scopes of a type the subject may use. The core adds the owner predicates.
 */
#[Spi(kind: SpiKind::Implement, since: '0.7')]
interface AssignmentScopeAccessAdapter
{
    public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool;

    /**
     * @param  list<AssignmentScopeRef>  $refs
     * @return array<string, bool> ref key => whether it is allowed
     */
    public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array;

    /**
     * @param  Builder<Model>  $contextQuery
     * @return Builder<Model>
     */
    public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder;
}
