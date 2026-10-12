<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Exceptions\DefinitionException;
use Illuminate\Contracts\Container\Container;

/**
 * The evaluator `ScopesPlugin` declares: build-time validation of filters and role bindings, and the eligibility of
 * `ScopeEligibility` for decisions, decision sets and writes.
 */
final readonly class ScopeEvaluator implements AssignmentScopeEvaluator
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
}
