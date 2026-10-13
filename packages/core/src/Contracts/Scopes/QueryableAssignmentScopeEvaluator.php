<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Context\AssignmentScopeRuntime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An evaluator that can also express its answer as SQL, so lists of visible resources stay exact.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface QueryableAssignmentScopeEvaluator extends AssignmentScopeEvaluator
{
    /**
     * Narrows a guarded candidate query over the definition's model to eligible contexts; returns false when this
     * runtime cannot be expressed exactly (core raises VisibilityNotSupportedException 'scope_query'). For a runtime
     * without a concrete context, false lets core ask again once per context the owner reaches, each with its concrete
     * runtime (at most 1000, otherwise 'scope_runtime_budget').
     *
     * @param  Builder<Model>  $contexts  guarded: only where-narrowing is accepted
     */
    public function eligibleContexts(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition,
        Builder $contexts): bool;
}
