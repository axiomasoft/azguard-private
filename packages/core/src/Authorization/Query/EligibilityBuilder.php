<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Query;

use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Policies\RuntimeInvoker;
use AzGuard\Scopes\AssignmentScopeRuntime;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/** Isolates every predicate from structural identity and from every other predicate. */
final class EligibilityBuilder
{
    /** @param list<AssignmentScopeFilter|class-string<AssignmentScopeFilter>|Closure> $filters */
    public static function matches(QueryableAssignmentScopeDefinition $definition, ResolvedAssignmentScope $resolved, array $filters, AssignmentScopeRuntime $runtime, Container $container): bool
    {
        $structural = $definition->query()->applyScopes()->withoutGlobalScopes();
        $query = clone $structural;
        $query->getQuery()->wheres = [];
        $query->getQuery()->setBindings([], 'where');
        $query->getQuery()->addNestedWhereQuery($structural->getQuery());
        $query->whereKey($resolved->ref->id());

        // Owner is defined by tenantOf(), not by a configurable column name or a filter.
        $record = (clone $query)->first();

        if ($record === null || ! $definition->tenantOf($record)->equals($resolved->tenant)
            || ! $resolved->tenant->equals($runtime->scope->tenant)) {
            return false;
        }

        self::apply($definition, $query, $filters, $runtime, $container);

        return $query->exists();
    }

    /** @param array<string, array{ResolvedAssignmentScope, AssignmentScopeRuntime}> $witnesses
     * @param  list<AssignmentScopeFilter|class-string<AssignmentScopeFilter>|Closure>  $filters
     * @return array<string, bool|Throwable>
     */
    public static function matchesMany(QueryableAssignmentScopeDefinition $definition, array $witnesses, array $filters, Container $container): array
    {
        $results = array_fill_keys(array_keys($witnesses), false);
        $query = null;
        $keys = array_keys($witnesses);
        foreach (array_values($witnesses) as $i => [$resolved, $runtime]) {
            if (! $resolved->tenant->equals($runtime->scope->tenant)) {
                continue;
            }

            try {
                $structural = $definition->query()->applyScopes()->withoutGlobalScopes();
                $branch = clone $structural;
                $branch->getQuery()->wheres = [];
                $branch->getQuery()->setBindings([], 'where');
                $branch->getQuery()->addNestedWhereQuery($structural->getQuery());
                $branch->whereKey($resolved->ref->id());
                self::apply($definition, $branch, $filters, $runtime, $container);
                // A witness tag prevents one passing role/field predicate from granting a sibling witness.
                $branch->selectRaw('? as azguard_batch_witness', [$i]);
                $query = $query === null ? $branch->toBase() : $query->unionAll($branch->toBase());
            } catch (Throwable $error) {
                $results[$keys[$i]] = $error;
            }
        }
        foreach ($query?->get() ?? [] as $row) {
            $results[$keys[(int) $row->azguard_batch_witness]] = true;
        }

        return $results;
    }

    /** @param Builder<Model> $query
     * @param  list<AssignmentScopeFilter|class-string<AssignmentScopeFilter>|Closure>  $filters
     */
    private static function apply(QueryableAssignmentScopeDefinition $definition, Builder $query, array $filters, AssignmentScopeRuntime $runtime, Container $container): void
    {
        $structural = $definition->query()->applyScopes()->withoutGlobalScopes();
        foreach ($filters as $filter) {
            $group = clone $structural;
            $group->getQuery()->wheres = [];
            $group->getQuery()->setBindings([], 'where');
            $guard = new QueryGuard;
            $predicate = new PredicateBuilder($group, $guard);
            $shape = self::shape($predicate);
            $filter = is_string($filter) ? $container->make($filter) : $filter;

            if ($filter instanceof AssignmentScopeFilter) {
                $filter->apply($predicate, $runtime);
            } elseif ($filter instanceof Closure) {
                $result = (new RuntimeInvoker($container))->invoke($filter, [
                    'query' => $predicate, 'runtime' => $runtime, 'user' => $runtime->user,
                    'role' => $runtime->role, 'grant' => $runtime->grant, 'actor' => $runtime->actor,
                    'actorModel' => $runtime->actorModel, 'panel' => $runtime->panel,
                    'scope' => $runtime->scope, 'now' => $runtime->now, 'phase' => $runtime->phase,
                ]);

                if ($result !== null && $result !== $predicate) {
                    throw new RuntimeException('An eligibility filter returned a replacement query or terminal result.');
                }
            } else {
                throw new RuntimeException('Invalid assignment-scope filter.');
            }

            $guard->validate();
            self::validateShape($predicate, $shape);
            $query->getQuery()->addNestedWhereQuery($predicate->getQuery());
        }

    }

    /** @param Builder<Model> $builder
     * @return array<string,mixed>
     */
    public static function shape(Builder $builder): array
    {
        $shape = get_object_vars($builder->getQuery());
        unset($shape['wheres'], $shape['bindings']);
        $bindings = $builder->getQuery()->getRawBindings();
        unset($bindings['where']);
        $shape['bindings'] = $bindings;
        $shape['model'] = $builder->getModel();
        $shape['eagerLoads'] = $builder->getEagerLoads();
        $shape['removedScopes'] = $builder->removedScopes();

        if ($builder instanceof PredicateBuilder) {
            $shape['eloquent'] = $builder->configuration();
        }

        return $shape;
    }

    /** @param Builder<Model> $builder
     * @param  array<string,mixed>  $before
     */
    public static function validateShape(Builder $builder, array $before): void
    {
        if (self::shape($builder) !== $before) {
            throw new RuntimeException('Assignment-scope filters cannot alter query structure, model or execution settings.');
        }
    }
}
