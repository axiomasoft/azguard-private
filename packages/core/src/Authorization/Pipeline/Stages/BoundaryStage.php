<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline\Stages;

use AzGuard\Authorization\BatchInputs;
use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Authorization\ScopeEligibility;
use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Contracts\Scopes\TenantResolver;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Scopes\CurrentContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final readonly class BoundaryStage
{
    public function __construct(private Container $container, private CurrentContext $current) {}

    /** @return array{EvaluationFrame, ?Decision} */
    public function resolve(AccessRequest $request, EvaluationFrame $frame, Trace $trace, bool $structural = true): array
    {
        $panel = $frame->panel();
        $ambient = $this->current->get($panel);
        $resource = null;
        $selected = AccessScope::in(
            tenant: $request->tenant() ?? $ambient->tenant ?? TenantRef::global(),
            context: $request->context() ?? ($panel->scopes()->mode() === 'none' ? null : $ambient?->context),
        );
        $frame = $frame->withScope($selected);
        $hint = $request->tenant() !== null || $request->context() !== null || $ambient !== null ? $selected : null;

        try {
            if ($request->resource() !== null) {
                $resource = $this->resourceScope(resource: $request->resource(), frame: $frame, selected: $hint);

                if ($resource === null && $panel->tenants()->mode() === 'required') {
                    return [$frame, $this->deny($frame, DecisionReason::ResourceScopeMissing)];
                }
            }
            $tenant = $request->tenant() ?? $resource->tenant ?? $ambient?->tenant;
            $context = $request->context() ?? $resource->context
                ?? ($panel->scopes()->mode() === 'none' ? null : $ambient?->context);

            if ($tenant === null && $panel->tenants()->mode() !== 'none') {
                $tenant = $this->resolveTenant($frame);
            }

            if ($context === null && $panel->scopes()->mode() !== 'none') {
                $context = $this->resolveContext($frame);
            }
            $frame = $frame->withScope(AccessScope::in(tenant: $tenant ?? TenantRef::global(), context: $context));
        } catch (Throwable $error) {
            $trace->error('boundary', DecisionReason::AssignmentScopeFilterError->value, 'scope', $error);

            return [$frame, $this->deny($frame, DecisionReason::AssignmentScopeFilterError)];
        }

        if ($resource !== null) {
            $ownerCandidate = $request->tenant() ?? $ambient?->tenant;

            if (($ownerCandidate !== null && ! $ownerCandidate->equals($resource->tenant))
                || ! $frame->scope()->tenant->equals($resource->tenant)) {
                return [$frame, $this->deny($frame, DecisionReason::TenantMismatch)];
            }

            if (! $frame->scope()->context->equals($resource->context)) {
                return [$frame, $this->deny($frame, DecisionReason::AssignmentScopeMismatch)];
            }
        }

        if (($denial = $this->decide($frame)) !== null) {
            return [$frame, $denial];
        }

        return $structural ? $this->structural($request, $frame, $trace) : [$frame, null];
    }

    /** @return array{EvaluationFrame, ?Decision} */
    public function structural(AccessRequest $request, EvaluationFrame $frame, Trace $trace, ?BatchInputs $inputs = null): array
    {
        $panel = $frame->panel();

        if (! $frame->scope()->context->isGlobal()) {
            $definition = $panel->scopeDefinition($frame->scope()->context->type() ?? '');

            if ($definition === null) {
                return [$frame, $this->deny($frame, DecisionReason::AssignmentScopeNotAccepted)];
            }

            try {
                $resolved = $inputs === null ? $definition->resolve($frame->scope()->context) : $inputs->scope($frame);

                if ($resolved === null) {
                    return [$frame, $this->deny($frame, DecisionReason::AssignmentScopeNotAccepted)];
                }

                if (! $resolved->ref->equals($frame->scope()->context) || ! $resolved->tenant->equals($frame->scope()->tenant)) {
                    return [$frame, $this->deny($frame, DecisionReason::AssignmentScopeMismatch)];
                }

                if ($resolved->record !== null) {
                    $model = $definition->model();
                    $key = $resolved->record->getKey();

                    if (($model !== null && ! $resolved->record instanceof $model)
                        || (! is_int($key) && ! is_string($key))
                        || (string) $key !== $frame->scope()->context->id()) {
                        return [$frame, $this->deny($frame, DecisionReason::AssignmentScopeMismatch)];
                    }
                }
                $frame = $frame->withAssignmentScope($resolved);

                if (! (new ScopeEligibility($this->container))->common($request, $frame, $inputs, $trace)) {
                    return [$frame, $this->deny($frame, DecisionReason::AssignmentScopeIneligible)];
                }
            } catch (Throwable $error) {
                $trace->error('filter', DecisionReason::AssignmentScopeFilterError->value, $definition::class, $error);

                return [$frame, $this->deny($frame, DecisionReason::AssignmentScopeFilterError)];
            }
        }

        return [$frame, null];
    }

    public function decide(EvaluationFrame $frame): ?Decision
    {
        $tenantPolicy = $frame->panel()->tenants();

        if ($tenantPolicy->mode() === 'required') {
            if ($frame->scope()->tenant->isGlobal()) {
                return $this->deny($frame, DecisionReason::TenantRequired);
            }

            if ($frame->scope()->tenant->type() !== $tenantPolicy->definition()?->type()) {
                return $this->deny($frame, DecisionReason::TenantMismatch);
            }
        } elseif (! $frame->scope()->tenant->isGlobal()) {
            return $this->deny($frame, DecisionReason::TenantMismatch);
        }

        $mode = $frame->panel()->scopes()->mode();

        if ($mode === 'required' && $frame->scope()->context->isGlobal()) {
            return $this->deny($frame, DecisionReason::AssignmentScopeRequired);
        }

        if (! $frame->scope()->context->isGlobal()
            && ($mode === 'none' || $frame->panel()->scopeDefinition($frame->scope()->context->type() ?? '') === null)) {
            return $this->deny($frame, DecisionReason::AssignmentScopeNotAccepted);
        }

        return null;
    }

    private function resourceScope(object $resource, EvaluationFrame $frame, ?AccessScope $selected): ?AccessScope
    {
        foreach ($frame->panel()->resourceScopes() as $class => $declared) {
            if (! $resource instanceof $class) {
                continue;
            }
            $resolver = is_string($declared) ? $this->container->make($declared) : $declared;

            if (! $resolver instanceof ResourceScopeResolver) {
                throw new RuntimeException('Resource resolver does not implement ResourceScopeResolver.');
            }

            return $resolver->resolve(resource: $resource, selected: $selected);
        }

        if ($resource instanceof ProvidesAccessScope) {
            return $resource->azguardScope();
        }

        if ($frame->panel()->tenants()->mode() === 'none' && $resource instanceof ProvidesAssignmentScope) {
            return AccessScope::in(tenant: TenantRef::global(), context: $resource->azguardAssignmentScope());
        }

        return null;
    }

    private function resolveTenant(EvaluationFrame $frame): ?TenantRef
    {
        foreach ($frame->panel()->tenantResolvers() as $declared) {
            $resolver = is_string($declared) ? $this->container->make($declared) : $declared;

            if (! $resolver instanceof TenantResolver) {
                throw new RuntimeException('Tenant resolver does not implement TenantResolver.');
            }
            $tenant = $resolver->resolve($this->container->make(Request::class));

            if ($tenant !== null) {
                return $tenant;
            }
        }

        return null;
    }

    private function resolveContext(EvaluationFrame $frame): ?AssignmentScopeRef
    {
        foreach ($frame->panel()->scopeResolvers() as $declared) {
            $resolver = is_string($declared) ? $this->container->make($declared) : $declared;

            if (! $resolver instanceof AssignmentScopeResolver) {
                throw new RuntimeException('Assignment scope resolver does not implement AssignmentScopeResolver.');
            }
            $context = $resolver->resolve($this->container->make(Request::class));

            if ($context !== null) {
                return $context;
            }
        }

        return null;
    }

    private function deny(EvaluationFrame $frame, DecisionReason $reason): Decision
    {
        return Decision::deny(reason: $reason, state: $frame->state(), scope: $frame->scope());
    }
}
