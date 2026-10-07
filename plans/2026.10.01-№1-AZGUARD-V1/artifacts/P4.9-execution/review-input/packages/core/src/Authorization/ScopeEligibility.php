<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Query\EligibilityBuilder;
use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/** Evaluates live scope predicates without caching decisions or storing an ambient runtime. */
final readonly class ScopeEligibility
{
    public function __construct(private Container $container) {}

    public function common(AccessRequest $request, EvaluationFrame $frame, ?BatchInputs $batch = null): bool
    {
        if ($frame->scope()->context->isGlobal()) {
            return true;
        }

        $definition = $this->definition($frame);
        $runtime = $this->runtime($request->subject(), $frame, common: true);

        return $this->native($definition, $definition, $frame, $runtime, $batch)
            && $this->external($frame, $runtime, $batch);
    }

    public function contribution(AccessRequest $request, EvaluationFrame $frame, ?BatchInputs $batch = null): bool
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
            foreach ($role->scopes() as $declared) {
                $binding = is_string($declared) ? $this->container->make($declared) : $declared;

                if (! $binding instanceof AssignmentScopeDefinition) {
                    throw new RuntimeException('A runtime role binding must be an assignment scope definition.');
                }

                if ($binding->type() !== $frame->scope()->context->type()) {
                    continue;
                }

                if ($binding::class !== $definition::class || $binding->model() !== $definition->model()) {
                    throw new RuntimeException('A runtime role binding changed the registered scope identity.');
                }

                if ($binding->model() !== null && $frame->assignmentScope?->record === null) {
                    throw new RuntimeException('A model-required scope binding has no resolved record.');
                }

                if (! $this->native($definition, $binding, $frame, $runtime, $batch)) {
                    return false;
                }
            }
        }

        return $this->external($frame, $runtime, $batch);
    }

    private function definition(EvaluationFrame $frame): AssignmentScopeDefinition
    {
        return $frame->panel()->scopeDefinition($frame->scope()->context->type() ?? '')
            ?? throw new RuntimeException('Selected scope definition is missing.');
    }

    private function native(AssignmentScopeDefinition $definition, AssignmentScopeDefinition $configuration, EvaluationFrame $frame, AssignmentScopeRuntime $runtime, ?BatchInputs $batch): bool
    {
        $filters = $configuration instanceof ConfigurableAssignmentScopeDefinition ? $configuration->settings()->filters : [];

        if ($filters === []) {
            return true;
        }

        if (! $definition instanceof QueryableAssignmentScopeDefinition) {
            throw new RuntimeException('Native scope filters require a queryable definition.');
        }

        $resolved = $frame->assignmentScope ?? throw new RuntimeException('Scope eligibility requires structural resolution.');

        return $batch === null ? EligibilityBuilder::matches($definition, $resolved, $filters, $runtime, $this->container)
            : $batch->native($definition, $configuration, $filters, $runtime, $frame);
    }

    private function external(EvaluationFrame $frame, AssignmentScopeRuntime $runtime, ?BatchInputs $batch): bool
    {
        $declared = $frame->panel()->scopes()->adapters()[$frame->scope()->context->type() ?? ''] ?? null;

        if ($declared === null) {
            return true;
        }

        $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

        if (! $adapter instanceof AssignmentScopeAccessAdapter) {
            throw new RuntimeException('Scope adapter resolver did not return AssignmentScopeAccessAdapter.');
        }

        return $batch === null ? $adapter->allows($frame->scope()->context, $runtime)
            : $batch->external($adapter, $frame, $runtime);
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
