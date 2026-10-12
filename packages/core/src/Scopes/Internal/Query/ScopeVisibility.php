<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal\Query;

use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Scopes\Internal\ScopeConfiguration;
use AzGuard\Scopes\Query\EligibilityBuilder;
use AzGuard\Scopes\RoleBindings;
use AzGuard\Support\ModelKey;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The exact SQL form of `ScopeEligibility` for lists: the native filters of the definition and, for a contribution
 * with a role, of its bindings, each as its own AND group, and the access adapter in a separate guarded group beside
 * them. A runtime without a concrete context whose filters or adapter would inspect it is expressed as one branch
 * per context of the type, each with its concrete runtime; more than 1000 contexts cannot be expressed exactly.
 */
final readonly class ScopeVisibility
{
    public const int CONTEXT_BUDGET = 1000;

    public function __construct(private Container $container) {}

    /** @param Builder<Model> $contexts */
    public function eligibleContexts(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition, Builder $contexts): bool
    {
        $configurations = [$definition, ...($runtime->role === null ? [] : RoleBindings::of($runtime->role, $definition, $this->container))];
        $adapter = $this->adapter($runtime, $definition);
        $filtered = array_filter($configurations, static fn (AssignmentScopeDefinition $configuration): bool => self::filters($configuration) !== []) !== [];

        if (! $runtime->scope->context->isGlobal() || ($adapter === null && ! $filtered)) {
            $this->narrow($runtime, $definition, $configurations, $adapter, $contexts);

            return true;
        }

        // Filters and adapters may read runtime.scope: give every context of the type its own concrete runtime.
        $records = $definition->query()->limit(self::CONTEXT_BUDGET + 1)->get();

        if ($records->count() > self::CONTEXT_BUDGET) {
            throw new VisibilityNotSupportedException('scope_runtime_budget');
        }
        $contexts->where(function (Builder $or) use ($records, $runtime, $definition, $configurations, $adapter): void {
            $or->whereRaw('1 = 0');

            foreach ($records as $record) {
                $ref = AssignmentScopeRef::of($definition->type(), ModelKey::of($record));
                $local = new AssignmentScopeRuntime(panel: $runtime->panel, scope: AccessScope::in($runtime->scope->tenant, $ref), subject: $runtime->subject,
                    user: $runtime->user, role: $runtime->role, grant: $runtime->grant, actor: $runtime->actor, actorModel: $runtime->actorModel,
                    now: $runtime->now, phase: $runtime->phase, proposed: $runtime->proposed);
                $or->orWhere(function (Builder $branch) use ($local, $definition, $configurations, $adapter, $ref): void {
                    ModelKey::where($branch, (string) $ref->id());
                    $this->narrow($local, $definition, $configurations, $adapter, $branch);
                });
            }
        });

        return true;
    }

    /**
     * @param  list<AssignmentScopeDefinition>  $configurations
     * @param  Builder<Model>  $contexts
     */
    private function narrow(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition, array $configurations,
        ?AssignmentScopeAccessAdapter $adapter, Builder $contexts): void
    {
        foreach ($configurations as $configuration) {
            EligibilityBuilder::apply($definition, $contexts, self::filters($configuration), $runtime, $this->container);
        }

        if ($adapter === null) {
            return;
        }
        // The adapter gets its own group: an OR inside it can never escape the filters beside it.
        EligibilityBuilder::constrain($contexts, $adapter, $runtime);
    }

    private function adapter(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition): ?AssignmentScopeAccessAdapter
    {
        $declared = ScopeConfiguration::adapters($runtime->panel)[$definition->type()] ?? null;

        if ($declared === null) {
            return null;
        }
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
