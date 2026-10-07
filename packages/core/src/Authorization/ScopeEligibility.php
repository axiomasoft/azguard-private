<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Scopes\Query\EligibilityBuilder;
use AzGuard\Scopes\RoleBindings;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/** Evaluates live scope predicates without caching decisions or storing an ambient runtime. */
final readonly class ScopeEligibility
{
    public function __construct(private Container $container) {}

    public function common(AccessRequest $request, EvaluationFrame $frame, ?BatchInputs $batch = null, ?Trace $trace = null): bool
    {
        if ($frame->scope()->context->isGlobal()) {
            return true;
        }

        $definition = $this->definition($frame);
        $runtime = $this->runtime($request->subject(), $frame, common: true);

        return $this->native($definition, $definition, $frame, $runtime, $batch, $trace)
            && $this->external($frame, $runtime, $batch, $trace);
    }

    public function contribution(AccessRequest $request, EvaluationFrame $frame, ?BatchInputs $batch = null, ?Trace $trace = null): bool
    {
        if ($frame->scope()->context->isGlobal()) {
            return true;
        }

        $definition = $this->definition($frame);

        if ($frame->assignmentScope === null) {
            $resolved = $definition->resolve($frame->scope()->context);

            if ($resolved === null || ! $resolved->ref->equals($frame->scope()->context)
                || ! $resolved->tenant->equals($frame->scope()->tenant)) {
                return false;
            }
            $frame = $frame->withAssignmentScope($resolved);

            if (! $this->common($request, $frame, $batch)) {
                return false;
            }
        }
        $runtime = $this->runtime($request->subject(), $frame);
        $role = $frame->role();

        if ($role !== null) {
            foreach ($this->bindings($role, $definition, $frame->assignmentScope) as $binding) {
                if (! $this->native($definition, $binding, $frame, $runtime, $batch, $trace)) {
                    return false;
                }
            }
        }

        return $this->external($frame, $runtime, $batch, $trace);
    }

    /**
     * Assignment eligibility of one resolved context for the target: common filters and the access adapter with
     * `role` null, then the filters of the role binding and the adapter with the code role. Only validated proposed
     * values and the nullable actor of the runtime reach the filters; a global context passes.
     */
    public function assignment(AssignmentScopeRuntime $runtime, ?ResolvedAssignmentScope $resolved): bool
    {
        $ref = $runtime->scope->context;

        if ($ref->isGlobal()) {
            return true;
        }

        if ($resolved === null || ! $resolved->ref->equals($ref) || ! $resolved->tenant->equals($runtime->scope->tenant)) {
            return false;
        }
        $definition = $runtime->panel->scopeDefinition($ref->type() ?? '') ?? throw new RuntimeException('Selected scope definition is missing.');
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

    /** @return list<AssignmentScopeDefinition> role bindings of the selected type, checked against the registered definition */
    private function bindings(BaseRole $role, AssignmentScopeDefinition $definition, ?ResolvedAssignmentScope $resolved): array
    {
        $bindings = RoleBindings::of($role, $definition, $this->container);

        foreach ($bindings as $binding) {
            if ($binding->model() !== null && $resolved?->record === null) {
                throw new RuntimeException('A model-required scope binding has no resolved record.');
            }
        }

        return $bindings;
    }

    private function filtersAllow(AssignmentScopeDefinition $definition, AssignmentScopeDefinition $configuration, ResolvedAssignmentScope $resolved, AssignmentScopeRuntime $runtime): bool
    {
        $filters = $configuration instanceof ConfigurableAssignmentScopeDefinition ? $configuration->settings()->filters : [];

        if ($filters === []) {
            return true;
        }

        if (! $definition instanceof QueryableAssignmentScopeDefinition) {
            throw new RuntimeException('Native scope filters require a queryable definition.');
        }

        return EligibilityBuilder::matches($definition, $resolved, $filters, $runtime, $this->container);
    }

    private function adapterAllows(AssignmentScopeRuntime $runtime): bool
    {
        $declared = $runtime->panel->scopes()->adapters()[$runtime->scope->context->type() ?? ''] ?? null;

        if ($declared === null) {
            return true;
        }
        $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

        if (! $adapter instanceof AssignmentScopeAccessAdapter) {
            throw new RuntimeException('Scope adapter resolver did not return AssignmentScopeAccessAdapter.');
        }

        return $adapter->allows($runtime->scope->context, $runtime);
    }

    private function definition(EvaluationFrame $frame): AssignmentScopeDefinition
    {
        return $frame->panel()->scopeDefinition($frame->scope()->context->type() ?? '')
            ?? throw new RuntimeException('Selected scope definition is missing.');
    }

    private function native(AssignmentScopeDefinition $definition, AssignmentScopeDefinition $configuration, EvaluationFrame $frame, AssignmentScopeRuntime $runtime, ?BatchInputs $batch, ?Trace $trace = null): bool
    {
        $filters = $configuration instanceof ConfigurableAssignmentScopeDefinition ? $configuration->settings()->filters : [];

        if ($filters === []) {
            return true;
        }

        if (! $definition instanceof QueryableAssignmentScopeDefinition) {
            throw new RuntimeException('Native scope filters require a queryable definition.');
        }

        $resolved = $frame->assignmentScope ?? throw new RuntimeException('Scope eligibility requires structural resolution.');

        $allowed = $batch === null ? EligibilityBuilder::matches($definition, $resolved, $filters, $runtime, $this->container)
            : $batch->native($definition, $configuration, $filters, $runtime, $frame);
        $trace?->record('filter', $allowed ? 'pass' : 'false', $definition::class,
            detail: ['filters' => array_map(static fn ($filter): string => is_string($filter) ? $filter : $filter::class, $filters)],
            outcome: $allowed ? 'pass' : 'deny');

        return $allowed;
    }

    private function external(EvaluationFrame $frame, AssignmentScopeRuntime $runtime, ?BatchInputs $batch, ?Trace $trace = null): bool
    {
        $declared = $frame->panel()->scopes()->adapters()[$frame->scope()->context->type() ?? ''] ?? null;

        if ($declared === null) {
            return true;
        }

        $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

        if (! $adapter instanceof AssignmentScopeAccessAdapter) {
            throw new RuntimeException('Scope adapter resolver did not return AssignmentScopeAccessAdapter.');
        }

        $allowed = $batch === null ? $adapter->allows($frame->scope()->context, $runtime)
            : $batch->external($adapter, $frame, $runtime);
        $trace?->record('filter', $allowed ? 'pass' : 'false', $adapter::class, outcome: $allowed ? 'pass' : 'deny');

        return $allowed;
    }

    public function runtime(SubjectRef $subject, EvaluationFrame $frame, bool $common = false): AssignmentScopeRuntime
    {
        return new AssignmentScopeRuntime(
            panel: $frame->panel(), scope: $frame->scope(), subject: $subject, user: $frame->subjectModel(),
            role: $common ? null : $frame->role(), grant: $common ? null : $frame->grant(), actor: $frame->actor(), actorModel: $frame->actorModel(),
            now: $frame->now(), phase: AssignmentScopePhase::Access,
        );
    }
}
