<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal\Query;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use AzGuard\Scopes\Contracts\ConfigurableAssignmentScopeDefinition;
use AzGuard\Scopes\Internal\RoleBindings;
use AzGuard\Scopes\Internal\ScopeConfiguration;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The exact SQL form of `ScopeEligibility` for lists: the native filters of the definition and, for a contribution
 * with a role, of its bindings, each as its own AND group, and the access adapter in a separate guarded group beside
 * them. A runtime without a concrete context whose filters or adapter would inspect it is refused, so core specializes
 * it per context.
 */
final readonly class ScopeVisibility
{
    public function __construct(private Container $container) {}

    /**
     * A runtime without a concrete context whose filters or adapter would inspect it cannot be expressed at once: the
     * answer is false and core asks again for every context with its concrete runtime.
     *
     * @param  Builder<Model>  $contexts
     */
    public function eligibleContexts(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition, Builder $contexts): bool
    {
        $configurations = [$definition, ...($runtime->role === null ? [] : RoleBindings::of($runtime->role, $definition, $this->container))];
        $adapter = $this->declaredAdapter($runtime, $definition);
        $filtered = array_filter($configurations, static fn (AssignmentScopeDefinition $configuration): bool => self::filters($configuration) !== []) !== [];

        if ($runtime->scope->context->isGlobal() && ($adapter !== null || $filtered)) {
            return false;
        }
        $this->narrow($runtime, $definition, $configurations, $adapter, $contexts);

        return true;
    }

    /**
     * @param  list<AssignmentScopeDefinition>  $configurations
     * @param  AssignmentScopeAccessAdapter|class-string<AssignmentScopeAccessAdapter>|null  $adapter
     * @param  Builder<Model>  $contexts
     */
    private function narrow(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition, array $configurations,
        AssignmentScopeAccessAdapter|string|null $adapter, Builder $contexts): void
    {
        foreach ($configurations as $configuration) {
            EligibilityBuilder::apply($definition, $contexts, self::filters($configuration), $runtime, $this->container);
        }

        if ($adapter === null) {
            return;
        }
        // The adapter gets its own group: an OR inside it can never escape the filters beside it.
        EligibilityBuilder::constrain($contexts, $this->resolved($adapter), $runtime);
    }

    /** @return AssignmentScopeAccessAdapter|class-string<AssignmentScopeAccessAdapter>|null */
    private function declaredAdapter(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition): AssignmentScopeAccessAdapter|string|null
    {
        return ScopeConfiguration::adapters($runtime->panel)[$definition->type()] ?? null;
    }

    /** A class-string adapter is made anew on every use, as the scalar path makes it for every concrete context. */
    private function resolved(AssignmentScopeAccessAdapter|string $declared): AssignmentScopeAccessAdapter
    {
        $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

        if (! $adapter instanceof AssignmentScopeAccessAdapter) {
            throw new VisibilityNotSupportedException('scope_adapter');
        }

        return $adapter;
    }

    /** @return list<AssignmentScopeFilter|class-string<AssignmentScopeFilter>|Closure> */
    private static function filters(AssignmentScopeDefinition $configuration): array
    {
        return $configuration instanceof ConfigurableAssignmentScopeDefinition ? $configuration->settings()->filters : [];
    }
}
