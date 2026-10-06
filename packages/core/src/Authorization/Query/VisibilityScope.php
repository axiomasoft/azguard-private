<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Query;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\ScopeEligibility;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use ReflectionNamedType;

/** The resource's explicitly declared single context, its structural owner and live eligibility. */
final readonly class VisibilityScope
{
    /** @param Builder<*> $host */
    public function __construct(
        private Builder $host,
        public QueryableAssignmentScopeDefinition $definition,
        private ?string $relation,
        private Container $container,
    ) {
        $model = $definition->model();

        if ($model === null) {
            throw new VisibilityNotSupportedException('external_scope');
        }

        if ($relation === null) {
            if ($host->getModel()::class !== $model) {
                throw new VisibilityNotSupportedException('resource_context_mapping');
            }
        } else {
            if (! method_exists($host->getModel(), $relation)) {
                throw new VisibilityNotSupportedException('resource_context_relation');
            }
            $method = new ReflectionMethod($host->getModel(), $relation);
            $return = $method->getReturnType();

            if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() !== 0
                || ! $return instanceof ReflectionNamedType || ! is_a($return->getName(), BelongsTo::class, true)) {
                throw new VisibilityNotSupportedException('resource_context_relation');
            }
            // A single belongs-to context avoids ambiguous scalar scope for a resource.
            $related = Relation::noConstraints(fn () => $host->getModel()->{$relation}());

            if (! $related instanceof BelongsTo || $related->getRelated()::class !== $model
                || $related->getRelated()->getConnection() !== $host->getModel()->getConnection()) {
                throw new VisibilityNotSupportedException('resource_context_relation');
            }
        }
    }

    /** @return Builder<Model> */
    public function query(AccessRequest $request, EvaluationFrame $frame, bool $common = false, bool $eligibility = true): Builder
    {
        $query = $this->definition->query();

        if ($query->getModel()::class !== $this->definition->model()
            || $query->getModel()->getConnection() !== $this->host->getModel()->getConnection()) {
            throw new VisibilityNotSupportedException('context_query_identity');
        }
        $compiler = new PredicateCompiler;
        $compiler->constrain($query, $this->owner($query, $request, $frame));

        if (! $frame->scope()->context->isGlobal()) {
            $query->whereKey($frame->scope()->context->id());
        }

        if (! $eligibility) {
            return $query;
        }
        $runtime = (new ScopeEligibility($this->container))->runtime($request->subject(), $frame, common: $common);
        $definitions = [$this->definition];

        if (! $common && $frame->role() !== null) {
            foreach ($frame->role()->scopes() as $declared) {
                $binding = is_string($declared) ? $this->container->make($declared) : $declared;

                if ($binding->type() === $this->definition->type()) {
                    if ($binding::class !== $this->definition::class || $binding->model() !== $this->definition->model()) {
                        throw new VisibilityNotSupportedException('role_scope_identity');
                    }
                    $definitions[] = $binding;
                }
            }
        }
        $declared = $frame->panel()->scopes()->adapters()[$this->definition->type()] ?? null;

        if ($frame->scope()->context->isGlobal() && ($declared !== null || array_filter($definitions,
            static fn ($definition): bool => $definition instanceof ConfigurableAssignmentScopeDefinition && $definition->settings()->filters !== []) !== [])) {
            // A native filter can inspect runtime.scope: preserve the concrete scalar runtime per context.
            // Rebuild from the structural owner query, before any filter has seen a global placeholder.
            $structural = $this->definition->query();
            $compiler->constrain($structural, $this->owner($structural, $request, $frame));
            $records = (clone $structural)->limit(1001)->get();

            if ($records->count() > 1000) {
                throw new VisibilityNotSupportedException('scope_runtime_budget');
            }
            $structural->where(function (Builder $or) use ($records, $request, $frame, $common): void {
                $or->whereRaw('1 = 0');
                foreach ($records as $record) {
                    $local = $this->frame($frame, AssignmentScopeRef::of($this->definition->type(), $record->getKey()));
                    $branch = $this->query($request, $local, $common);
                    $or->orWhereIn($record->getQualifiedKeyName(), $branch->select($record->getQualifiedKeyName())->toBase());
                }
            });

            return $structural;
        }
        foreach ($definitions as $definition) {
            $filters = $definition instanceof ConfigurableAssignmentScopeDefinition ? $definition->settings()->filters : [];
            EligibilityBuilder::apply($this->definition, $query, $filters, $runtime, $this->container);
        }

        if ($declared !== null) {
            $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

            if (! $adapter instanceof AssignmentScopeAccessAdapter) {
                throw new VisibilityNotSupportedException('scope_adapter');
            }
            $group = $query->getModel()->newModelQuery();
            $guard = new QueryGuard;
            $predicate = new PredicateBuilder($group, $guard);
            $shape = EligibilityBuilder::shape($predicate);

            if ($adapter->constrain($predicate, $runtime) !== $predicate) {
                throw new VisibilityNotSupportedException('replacement_scope_query');
            }
            $guard->validate();
            EligibilityBuilder::validateShape($predicate, $shape);
            $query->getQuery()->addNestedWhereQuery($predicate->getQuery());
        }

        return $query;
    }

    /** @param Builder<Model> $query */
    private function owner(Builder $query, AccessRequest $request, EvaluationFrame $frame): P
    {
        if ($this->definition instanceof FiltersAccessQueries) {
            $predicate = $this->definition->predicate($request, $query->getModel()::class, $frame);
            $predicate->assertBoolean();

            if (! $predicate->isSupported()) {
                throw new VisibilityNotSupportedException('owner_adapter');
            }

            return $predicate;
        }

        if ($this->definition instanceof ModelAssignmentScopeDefinition && ! $this->definition->hasOwner()
            && $frame->scope()->tenant->isGlobal()) {
            return P::pass();
        }
        // An arbitrary tenantOf callback cannot be inferred from a column name.
        // Materialize a bounded structural universe unless an exact owner adapter is declared.
        $probe = (new PredicateCompiler)->constrain(clone $query, P::pass());

        if ($probe->getQuery()->limit !== null || $probe->getQuery()->offset !== null) {
            throw new VisibilityNotSupportedException('limited_context_universe');
        }

        if (! $frame->scope()->context->isGlobal()) {
            $probe->whereKey($frame->scope()->context->id());
        }
        $records = $probe->limit(1001)->get();

        if ($records->count() > 1000) {
            throw new VisibilityNotSupportedException('owner_budget', $this->definition::class);
        }
        $ids = [];
        foreach ($records as $record) {
            if ($this->definition->tenantOf($record)->equals($frame->scope()->tenant)) {
                $ids[] = $record->getKey();
            }
        }

        return P::in($query->getModel()->getKeyName(), $ids);
    }

    /** @param Builder<*> $resource
     * @param  Builder<Model>  $context
     */
    public function constrain(Builder $resource, Builder $context): void
    {
        $key = $context->getModel()->getQualifiedKeyName();
        $context->select($key)->setEagerLoads([]);

        if ($this->relation === null) {
            $resource->whereIn($resource->getModel()->getQualifiedKeyName(), $context->toBase());

            return;
        }
        $resource->whereHas($this->relation, static fn (Builder $related): Builder => $related->whereIn($related->getModel()->getQualifiedKeyName(), $context->toBase()));
    }

    public function frame(EvaluationFrame $frame, AssignmentScopeRef $ref): EvaluationFrame
    {
        return $frame->withScope(AccessScope::in($frame->scope()->tenant, $ref));
    }
}
