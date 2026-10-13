<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Scopes\Internal\Query\ScopeVisibility;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;

/**
 * The evaluator `ScopesPlugin` declares: build-time validation of filters and role bindings, the eligibility of
 * `ScopeEligibility` for decisions, decision sets and writes, and its SQL form of `ScopeVisibility` for lists.
 */
final readonly class ScopeEvaluator implements QueryableAssignmentScopeEvaluator
{
    public function __construct(private Container $container) {}

    public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void
    {
        ScopeConfiguration::filters($definition, $this->container);

        if ($registered !== null && ! ScopeConfiguration::sameStructure($registered, $definition)) {
            throw new DefinitionException('A role binding changes the registered structural assignment scope '.$definition->type().'.');
        }
    }

    public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
    {
        return (new ScopeEligibility($this->container))->allows($runtime, $resolved);
    }

    public function allowsMany(array $checks): array
    {
        return (new ScopeEligibility($this->container))->allowsMany($checks);
    }

    public function eligibleContexts(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition, Builder $contexts): bool
    {
        return (new ScopeVisibility($this->container))->eligibleContexts($runtime, $definition, $contexts);
    }
}
