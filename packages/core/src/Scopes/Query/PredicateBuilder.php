<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Query;

use AzGuard\Kernel\Support\Narrow;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** @extends Builder<Model> */
final class PredicateBuilder extends Builder
{
    /** @param Builder<Model> $source */
    public function __construct(Builder $source, private QueryGuard $guard)
    {
        parent::__construct(new PredicateQuery($source->getQuery(), $guard));
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

    /** @return array<string,mixed> */
    public function configuration(): array
    {
        $configuration = Narrow::map(get_object_vars($this), 'predicate builder');
        unset($configuration['query'], $configuration['guard']);

        return $configuration;
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
            $before = EligibilityBuilder::shape($nested);
            $column($nested);
            EligibilityBuilder::validateShape($nested, $before);
            $this->query->addNestedWhereQuery($nested->getQuery(), $boolean);

            return $this;
        }

        // Two arguments mean "equals": the query builder tells them from an explicit null operator by their count.
        return func_num_args() === 2 ? parent::where($column, $operator) : parent::where($column, $operator, $value, $boolean);
    }

    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null): static
    {
        $guarded = function (Builder $query) use ($callback): void {
            $query->setQuery(new PredicateQuery($query->getQuery(), $this->guard));

            if ($callback !== null) {
                $predicate = new self($query, $this->guard);
                $callback($predicate);
                $query->setQuery($predicate->getQuery());
            }
        };

        return parent::has($relation, $operator, $count, $boolean, $guarded);
    }
}
