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
     * runtime cannot be expressed exactly (core raises VisibilityNotSupportedException 'scope_query').
     *
     * @param  Builder<Model>  $contexts  guarded: only where-narrowing is accepted
     * @param  Builder<Model>  $universe  the definition's context query already narrowed to the contexts the owner can reach:
     *                                    read it (count, enumerate) to bound a per-context expansion; narrowing it changes nothing
     */
    public function eligibleContexts(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition,
        Builder $contexts, Builder $universe): bool;
}
