<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal;

use AzGuard\Context\AssignmentScopePhase;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use AzGuard\Scopes\Contracts\AssignmentScopeMembership;
use AzGuard\Scopes\Contracts\ConfigurableAssignmentScopeDefinition;
use AzGuard\Scopes\Internal\Query\EligibilityBuilder;
use Closure;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

/**
 * The eligibility `ScopeEvaluator` answers for one structurally verified scope: native filters of the definition (a
 * runtime without role and contribution) or of the role bindings (a contribution), then the access adapter of the
 * scope type; in the Assignment phase scope membership first, then both. Settings come from the scope options of the
 * runtime's panel. Live predicates only: nothing is cached and no runtime is kept.
 */
final readonly class ScopeEligibility
{
    public function __construct(private Container $container) {}

    public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
    {
        $definition = $this->definition($runtime);

        if ($runtime->phase !== AssignmentScopePhase::Access) {
            return $this->assignment($runtime, $resolved, $definition);
        }

        foreach ($this->configurations($runtime, $definition, $resolved) as $configuration) {
            if (! $this->filtersAllow($definition, $configuration, $resolved, $runtime)) {
                return false;
            }
        }

        return $this->adapterAllows($runtime);
    }

    /**
     * The same answers for a decision set: native filters run once per definition and configuration for every check
     * that needs them, the access adapter once per check that passed them. A failing filter of one check fails the
     * call.
     *
     * @param  list<array{AssignmentScopeRuntime, ResolvedAssignmentScope}>  $checks
     * @return list<bool>
     */
    public function allowsMany(array $checks): array
    {
        $answers = array_fill(0, count($checks), true);
        $groups = [];

        foreach ($checks as $i => [$runtime, $resolved]) {
            if ($runtime->phase !== AssignmentScopePhase::Access) {
                $answers[$i] = $this->allows($runtime, $resolved);

                continue;
            }
            $definition = $this->definition($runtime);

            foreach ($this->configurations($runtime, $definition, $resolved) as $configuration) {
                $filters = self::filters($configuration);

                if ($filters === []) {
                    continue;
                }

                if (! $definition instanceof QueryableAssignmentScopeDefinition) {
                    throw new RuntimeException('Native scope filters require a queryable definition.');
                }
                $key = self::configurationKey($configuration);
                $groups[$key]['definition'] = $definition;
                $groups[$key]['filters'] = $filters;
                $groups[$key]['witnesses']['check:'.$i] = [$resolved, $runtime];
            }
        }

        foreach ($groups as ['definition' => $definition, 'filters' => $filters, 'witnesses' => $witnesses]) {
            foreach (array_chunk($witnesses, 100, true) as $chunk) {
                foreach (EligibilityBuilder::matchesMany($definition, $chunk, $filters, $this->container) as $check => $matches) {
                    if ($matches instanceof Throwable) {
                        throw $matches;
                    }
                    $i = (int) substr($check, strlen('check:'));
                    $answers[$i] = $answers[$i] && $matches;
                }
            }
        }

        foreach ($checks as $i => [$runtime]) {
            if ($answers[$i] && $runtime->phase === AssignmentScopePhase::Access) {
                $answers[$i] = $this->adapterAllowsMany($runtime);
            }
        }

        return array_values($answers);
    }

    /**
     * Assignment eligibility of one resolved context for the target: scope membership, common filters and the access
     * adapter with `role` null, then the filters of the role binding and the adapter with the code role. Only validated
     * proposed values and the nullable actor of the runtime reach the filters.
     *
     * @throws AssignmentScopeNotAcceptedException when the target is not a member of the scope
     */
    private function assignment(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved, AssignmentScopeDefinition $definition): bool
    {
        if ($runtime->phase === AssignmentScopePhase::Assignment && ! $this->member($runtime, $resolved)) {
            throw new AssignmentScopeNotAcceptedException('Subject '.$runtime->subject->key().' is not a member of assignment scope '.$resolved->ref->key().'.');
        }
        $common = new AssignmentScopeRuntime(panel: $runtime->panel, scope: $runtime->scope, subject: $runtime->subject, user: $runtime->user,
            role: null, grant: null, actor: $runtime->actor, actorModel: $runtime->actorModel, now: $runtime->now, phase: $runtime->phase,
            proposed: $runtime->proposed);

        if (! $this->filtersAllow($definition, $definition, $resolved, $common) || ! $this->adapterAllows($common)) {
            return false;
        }

        if ($runtime->role === null) {
            return true;
        }
        foreach ($this->bindings($runtime->role, $definition, $resolved) as $binding) {
            if (! $this->filtersAllow($definition, $binding, $resolved, $runtime)) {
                return false;
            }
        }

        return $this->adapterAllows($runtime);
    }

    private function member(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
    {
        $declared = ScopeConfiguration::membership($runtime->panel);

        if ($declared === null) {
            return true;
        }
        $membership = is_string($declared) ? $this->container->make($declared) : $declared;

        if (! $membership instanceof AssignmentScopeMembership) {
            throw new DefinitionException('Scope membership resolver did not return '.AssignmentScopeMembership::class.'.');
        }

        return $membership->isMember($runtime->subject, $resolved->ref);
    }

    /**
     * The configurations whose filters apply in the Access phase: the definition for a runtime without role and
     * contribution, the role bindings of the type for a contribution (none for a direct grant).
     *
     * @return list<AssignmentScopeDefinition>
     */
    private function configurations(AssignmentScopeRuntime $runtime, AssignmentScopeDefinition $definition, ResolvedAssignmentScope $resolved): array
    {
        if ($runtime->role === null && $runtime->grant === null) {
            return [$definition];
        }

        return $runtime->role === null ? [] : $this->bindings($runtime->role, $definition, $resolved);
    }

    /** @return list<AssignmentScopeDefinition> role bindings of the selected type, checked against the registered definition */
    private function bindings(BaseRole $role, AssignmentScopeDefinition $definition, ResolvedAssignmentScope $resolved): array
    {
        $bindings = RoleBindings::of($role, $definition, $this->container);

        foreach ($bindings as $binding) {
            if ($binding->model() !== null && $resolved->record === null) {
                throw new RuntimeException('A model-required scope binding has no resolved record.');
            }
        }

        return $bindings;
    }

    private function filtersAllow(AssignmentScopeDefinition $definition, AssignmentScopeDefinition $configuration, ResolvedAssignmentScope $resolved, AssignmentScopeRuntime $runtime): bool
    {
        $filters = self::filters($configuration);

        if ($filters === []) {
            return true;
        }

        if (! $definition instanceof QueryableAssignmentScopeDefinition) {
            throw new RuntimeException('Native scope filters require a queryable definition.');
        }

        return EligibilityBuilder::matches($definition, $resolved, $filters, $runtime, $this->container);
    }

    /** @return list<AssignmentScopeFilter|class-string<AssignmentScopeFilter>|Closure> */
    private static function filters(AssignmentScopeDefinition $configuration): array
    {
        return $configuration instanceof ConfigurableAssignmentScopeDefinition ? $configuration->settings()->filters : [];
    }

    private function adapterAllows(AssignmentScopeRuntime $runtime): bool
    {
        $adapter = $this->adapter($runtime);

        return $adapter === null || $adapter->allows($runtime->scope->context, $runtime);
    }

    private function adapterAllowsMany(AssignmentScopeRuntime $runtime): bool
    {
        $adapter = $this->adapter($runtime);

        if ($adapter === null) {
            return true;
        }
        $ref = $runtime->scope->context;

        return ($adapter->allowsMany([$ref], $runtime)[$ref->key()] ?? false) === true;
    }

    private function adapter(AssignmentScopeRuntime $runtime): ?AssignmentScopeAccessAdapter
    {
        $declared = ScopeConfiguration::adapters($runtime->panel)[$runtime->scope->context->type() ?? ''] ?? null;

        if ($declared === null) {
            return null;
        }
        $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

        if (! $adapter instanceof AssignmentScopeAccessAdapter) {
            throw new RuntimeException('Scope adapter resolver did not return AssignmentScopeAccessAdapter.');
        }

        return $adapter;
    }

    private function definition(AssignmentScopeRuntime $runtime): AssignmentScopeDefinition
    {
        return $runtime->panel->scopeDefinition($runtime->scope->context->type() ?? '')
            ?? throw new RuntimeException('Selected scope definition is missing.');
    }

    /** One group per filter configuration; runtime closures have no structural equality, so each instance is its own. */
    private static function configurationKey(AssignmentScopeDefinition $configuration): string
    {
        try {
            return hash('sha256', serialize($configuration));
        } catch (Throwable) {
            return 'object:'.spl_object_id($configuration);
        }
    }
}
