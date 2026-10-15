<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Internal\Query;

use AzGuard\Authorization\Internal\EvaluationFrame;
use AzGuard\Context\AssignmentScopeRuntime;
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
use AzGuard\Kernel\Internal\Narrow;
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
 * candidate query that only accepts narrowing. A global runtime it cannot express at once is specialized here into
 * one branch per context the owner reaches (at most `CONTEXT_BUDGET`); a concrete runtime it cannot express exactly
 * is unsupported, never approximated.
 */
final readonly class ResourceContextMapping
{
    /** Contexts a global list may expand into before it is unsupported. */
    public const int CONTEXT_BUDGET = 1000;

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
        $runtime = $frame->scopeRuntime($request->subject(), common: $common);
        $eligible = $this->eligible($evaluator, $runtime, $query);

        if ($eligible === null) {
            if (! $runtime->scope->context->isGlobal()) {
                throw new VisibilityNotSupportedException('scope_query', $evaluator::class);
            }
            $eligible = $this->perContext($evaluator, $request, $frame, $common, $query);
        }
        $query->getQuery()->addNestedWhereQuery($eligible->getQuery());

        return $query;
    }

    /**
     * The evaluator's narrowing of an isolated, guarded group over the context model, or null when it cannot express
     * this runtime exactly; a refused group is discarded, whatever it was narrowed to.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>|null
     */
    private function eligible(QueryableAssignmentScopeEvaluator $evaluator, AssignmentScopeRuntime $runtime, Builder $query): ?Builder
    {
        $guard = new QueryGuard;
        $contexts = new PredicateBuilder($query->getModel()->newModelQuery(), $guard);
        $shape = PredicateBuilder::shape($contexts);

        if (! $evaluator->eligibleContexts($runtime, $this->definition, $contexts)) {
            return null;
        }
        $guard->validate();
        PredicateBuilder::validateShape($contexts, $shape);

        return $contexts;
    }

    /**
     * A global runtime the evaluator cannot express at once: one branch per context the owner reaches, each narrowed for
     * its concrete runtime. Core enumerates and bounds the contexts; the branches apply only when every one is exact.
     *
     * @param  Builder<Model>  $owned  the context query already narrowed to the owner
     * @return Builder<Model>
     */
    private function perContext(QueryableAssignmentScopeEvaluator $evaluator, AccessRequest $request, EvaluationFrame $frame, bool $common, Builder $owned): Builder
    {
        $records = (clone $owned)->limit(self::CONTEXT_BUDGET + 1)->get();

        if ($records->count() > self::CONTEXT_BUDGET) {
            throw new VisibilityNotSupportedException('scope_runtime_budget', $evaluator::class);
        }
        $branches = $owned->getModel()->newModelQuery()->whereRaw('1 = 0');

        foreach ($records as $record) {
            $ref = AssignmentScopeRef::of($this->definition->type(), ModelKey::of($record));
            $runtime = $frame->withScope(AccessScope::in($frame->scope()->tenant, $ref))->scopeRuntime($request->subject(), common: $common);
            $eligible = $this->eligible($evaluator, $runtime, $owned) ?? throw new VisibilityNotSupportedException('scope_query', $evaluator::class);
            $branch = $owned->getModel()->newModelQuery();
            ModelKey::where($branch, $ref->id() ?? '');
            $branch->getQuery()->addNestedWhereQuery($eligible->getQuery());
            $branches->getQuery()->addNestedWhereQuery($branch->getQuery(), 'or');
        }

        return $branches;
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

        $description = ContextIdentity::describe($this->definition);

        // Without a tenant owner every record is global, but only `query()` equals `resolve()` when it says so.
        if (! $description->tenantOwner && $description->resolvesThroughQuery && $frame->scope()->tenant->isGlobal()) {
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
        $customResolver = ! $description->resolvesThroughQuery;
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
