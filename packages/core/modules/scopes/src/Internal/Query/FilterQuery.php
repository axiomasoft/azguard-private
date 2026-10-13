<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal\Query;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;
use UnexpectedValueException;

/**
 * The narrowing-only builder a filter or an access adapter of the Scopes module receives: it may add predicates, never
 * replace the query, create or delete models, change the query shape or execute it. Its base query refuses execution
 * through the shared {@see FilterGuard}; the unguarded query runs only after the group was validated.
 *
 * @internal
 *
 * @extends Builder<Model>
 */
final class FilterQuery extends Builder
{
    /** @param Builder<Model> $source */
    public function __construct(Builder $source, private FilterGuard $guard)
    {
        parent::__construct(self::guarded($source->getQuery(), $guard));
        foreach (get_object_vars($source) as $name => $value) {
            if ($name !== 'query') {
                $this->{$name} = $value;
            }
        }
    }

    public function setQuery($query): never
    {
        $this->guard->reject();
    }

    /** @param array<string,mixed> $attributes */
    public function newModelInstance($attributes = []): never
    {
        $this->guard->reject();
    }

    public function delete(): never
    {
        $this->guard->reject();
    }

    public function forceDelete(): never
    {
        $this->guard->reject();
    }

    /**
     * @param  array<int|string, mixed>|(Closure(static|QueryBuilder): mixed)|Expression|string  $column
     * @param  string  $boolean
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and'): static
    {
        if ($column instanceof Closure && $operator === null) {
            $nested = clone $this;
            $nested->getQuery()->wheres = [];
            $nested->getQuery()->setBindings([], 'where');
            $before = self::shape($nested);
            $column($nested);
            self::validateShape($nested, $before);
            $this->query->addNestedWhereQuery($nested->getQuery(), $boolean);

            return $this;
        }

        // Two arguments mean "equals": the query builder tells them from an explicit null operator by their count.
        return func_num_args() === 2 ? parent::where($column, $operator) : parent::where($column, $operator, $value, $boolean);
    }

    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null): static
    {
        $guarded = function (Builder $query) use ($callback): void {
            $query->setQuery(self::guarded($query->getQuery(), $this->guard));

            if ($callback !== null) {
                $filter = new self($query, $this->guard);
                $callback($filter);
                $query->setQuery($filter->getQuery());
            }
        };

        return parent::has($relation, $operator, $count, $boolean, $guarded);
    }

    /**
     * What a filter may not change: the query state except its where clauses, the model, eager loads, removed scopes
     * and, for a filter query, its Eloquent configuration.
     *
     * @param  Builder<Model>  $builder
     * @return array<string,mixed>
     */
    public static function shape(Builder $builder): array
    {
        $shape = self::properties($builder->getQuery());
        unset($shape['wheres'], $shape['bindings']);
        $bindings = $builder->getQuery()->getRawBindings();
        unset($bindings['where']);
        $shape['bindings'] = $bindings;
        $shape['model'] = $builder->getModel();
        $shape['eagerLoads'] = $builder->getEagerLoads();
        $shape['removedScopes'] = $builder->removedScopes();

        if ($builder instanceof self) {
            $configuration = self::properties($builder);
            unset($configuration['query'], $configuration['guard']);
            $shape['eloquent'] = $configuration;
        }

        return $shape;
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  array<string,mixed>  $before
     */
    public static function validateShape(Builder $builder, array $before): void
    {
        if (self::shape($builder) !== $before) {
            throw new RuntimeException('Assignment-scope filters cannot alter query structure, model or execution settings.');
        }
    }

    /**
     * The properties of the object visible here, by name.
     *
     * @return array<string,mixed>
     */
    private static function properties(object $object): array
    {
        $properties = [];

        foreach (get_object_vars($object) as $name => $value) {
            if (! is_string($name)) {
                throw new UnexpectedValueException('query state key must be a string, got '.get_debug_type($name).'.');
            }
            $properties[$name] = $value;
        }

        return $properties;
    }

    /** Query construction only: every way to execute the base query, or to register a callback that would, rejects. */
    private static function guarded(QueryBuilder $source, FilterGuard $guard): QueryBuilder
    {
        return new class($source, $guard) extends QueryBuilder
        {
            public function __construct(QueryBuilder $source, private readonly FilterGuard $guard)
            {
                parent::__construct($source->getConnection(), $source->getGrammar(), $source->getProcessor());
                foreach (get_object_vars($source) as $name => $value) {
                    // A guarded source (a relation query of has()) shares the guard this query was given.
                    if ($name !== 'guard') {
                        $this->{$name} = $value;
                    }
                }
            }

            public function newQuery(): self
            {
                return new self(new QueryBuilder($this->connection, $this->grammar, $this->processor), $this->guard);
            }

            public function applyBeforeQueryCallbacks(): never
            {
                $this->guard->reject();
            }

            protected function runSelect(): never
            {
                $this->guard->reject();
            }

            public function cursor(): never
            {
                $this->guard->reject();
            }

            public function explain(): never
            {
                $this->guard->reject();
            }

            public function toSql(): string
            {
                // Subqueries need SQL compilation, which Laravel normally routes through beforeQuery.
                if ($this->beforeQueryCallbacks !== []) {
                    throw new RuntimeException('Eligibility predicates cannot register query execution callbacks.');
                }

                return $this->grammar->compileSelect($this);
            }
        };
    }
}
