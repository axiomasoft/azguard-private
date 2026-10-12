<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Internal\Query;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Query\PredicateCompiler;
use AzGuard\Context\Internal\ContextIdentity;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeEvaluator;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Support\Narrow;
use AzGuard\Scopes\Internal\ScopeConfiguration;
use AzGuard\Scopes\Query\PredicateBuilder;
use AzGuard\Scopes\Query\QueryGuard;
use AzGuard\Support\ModelKey;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The resource's explicitly declared single context, its structural owner and the join to the resource branch. The
 * eligibility of the contexts comes from the panel's evaluator through `eligibleContexts()`, on an isolated, guarded
 * candidate query that only accepts narrowing; a runtime it cannot express exactly is unsupported, never
 * approximated.
 */
final readonly class ResourceContextMapping
{
    /** @param Builder<*> $host */
    public function __construct(
        private Builder $host,
        public QueryableAssignmentScopeDefinition $definition,
        private ?string $relation,
        private Container $container,
        private PredicateCompiler $compiler,
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
        $compiler = $this->compiler;

        if (! $eligibility) {
            $compiler->constrain($query, $this->owner($query, $request, $frame));

            if (! $frame->scope()->context->isGlobal()) {
                ModelKey::where($query, $frame->scope()->context->id());
            }

            return $query;
        }
        $requiresRecord = false;

        if (! $common && $frame->role() !== null) {
            foreach ($frame->role()->scopes() as $declared) {
                $binding = Narrow::instance(is_string($declared) ? $this->container->make($declared) : $declared, AssignmentScopeDefinition::class, 'role scope');

                if ($binding->type() === $this->definition->type()) {
                    if ($binding::class !== $this->definition::class || ! $binding instanceof QueryableAssignmentScopeDefinition || $binding->model() !== $this->definition->model()) {
                        throw new VisibilityNotSupportedException('role_scope_identity');
                    }
                    $requiresRecord = true;
                }
            }
        }
        $compiler->constrain($query, $this->owner($query, $request, $frame, $requiresRecord));

        if (! $frame->scope()->context->isGlobal()) {
            ModelKey::where($query, $frame->scope()->context->id());
        }
        $evaluator = ContextIdentity::evaluator($frame->panel(), $this->container);

        if (! $evaluator instanceof QueryableAssignmentScopeEvaluator) {
            throw new VisibilityNotSupportedException('scope_query', $evaluator === null ? null : $evaluator::class);
        }
        $group = $query->getModel()->newModelQuery();
        $guard = new QueryGuard;
        $contexts = new PredicateBuilder($group, $guard);
        $shape = PredicateBuilder::shape($contexts);

        if (! $evaluator->eligibleContexts($frame->scopeRuntime($request->subject(), common: $common), $this->definition, $contexts)) {
            throw new VisibilityNotSupportedException('scope_query', $evaluator::class);
        }
        $guard->validate();
        PredicateBuilder::validateShape($contexts, $shape);
        $query->getQuery()->addNestedWhereQuery($contexts->getQuery());

        return $query;
    }

    /** @param Builder<Model> $query */
    private function owner(Builder $query, AccessRequest $request, EvaluationFrame $frame, bool $requiresRecord = false): P
    {
        if ($this->definition instanceof FiltersAccessQueries) {
            $predicate = $this->definition->predicate($request, $query->getModel()::class, $frame);
            $predicate->assertBoolean();

            if (! $predicate->isSupported()) {
                throw new VisibilityNotSupportedException('owner_adapter');
            }

            return $predicate;
        }

        if (ScopeConfiguration::ownerless($this->definition)
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
            ModelKey::where($probe, $frame->scope()->context->id());
        }
        $records = $probe->limit(1001)->get();

        if ($records->count() > 1000) {
            throw new VisibilityNotSupportedException('owner_budget', $this->definition::class);
        }
        $ids = [];
        $customResolver = ! ScopeConfiguration::resolvesByQuery($this->definition);
        foreach ($records as $record) {
            if ($customResolver) {
                $ref = AssignmentScopeRef::of($this->definition->type(), ModelKey::of($record));
                $resolved = $this->definition->resolve($ref);

                if ($resolved === null || ! $resolved->ref->equals($ref) || ! $resolved->tenant->equals($frame->scope()->tenant)) {
                    continue;
                }

                if ($requiresRecord && $resolved->record === null) {
                    // Scalar treats this as an authority error, including when a direct grant also exists.
                    throw new VisibilityNotSupportedException('role_scope_record');
                }
                $model = $this->definition->model();

                if ($resolved->record !== null && (($model !== null && ! $resolved->record instanceof $model)
                    || (! is_int($resolved->record->getKey()) && ! is_string($resolved->record->getKey()))
                    || (string) $resolved->record->getKey() !== $ref->id())) {
                    continue;
                }
                $ids[] = $record->getKey();
            } elseif ($this->definition->tenantOf($record)->equals($frame->scope()->tenant)) {
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
