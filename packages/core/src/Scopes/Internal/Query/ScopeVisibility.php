<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal\Query;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Scopes\Internal\ScopeConfiguration;
use AzGuard\Scopes\Query\EligibilityBuilder;
use AzGuard\Scopes\RoleBindings;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The exact SQL form of `ScopeEligibility` for lists: the native filters of the definition and, for a contribution
 * with a role, of its bindings, then the access adapter of the type, all as narrowing of the guarded candidate
 * query. A runtime without a concrete context whose filters or adapter would inspect it cannot be expressed: the
 * answer is false and nothing is added.
 */
final readonly class ScopeVisibility
{
    public function __construct(private Container $container) {}

    /** @param Builder<Model> $contexts */
    public function eligibleContexts(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition, Builder $contexts): bool
    {
        $configurations = [$definition, ...($runtime->role === null ? [] : RoleBindings::of($runtime->role, $definition, $this->container))];
        $declared = ScopeConfiguration::adapters($runtime->panel)[$definition->type()] ?? null;
        $filtered = array_filter($configurations, static fn ($configuration): bool => $configuration instanceof ConfigurableAssignmentScopeDefinition
            && $configuration->settings()->filters !== []) !== [];

        if ($runtime->scope->context->isGlobal() && ($declared !== null || $filtered)) {
            return false;
        }

        foreach ($configurations as $configuration) {
            $filters = $configuration instanceof ConfigurableAssignmentScopeDefinition ? $configuration->settings()->filters : [];
            EligibilityBuilder::apply($definition, $contexts, $filters, $runtime, $this->container);
        }

        if ($declared !== null) {
            $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

            if (! $adapter instanceof AssignmentScopeAccessAdapter) {
                throw new VisibilityNotSupportedException('scope_adapter');
            }

            if ($adapter->constrain($contexts, $runtime) !== $contexts) {
                throw new VisibilityNotSupportedException('replacement_scope_query');
            }
        }

        return true;
    }
}
