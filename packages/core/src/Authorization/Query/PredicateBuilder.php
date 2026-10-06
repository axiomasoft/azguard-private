<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Query;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        $configuration = get_object_vars($this);
        unset($configuration['query'], $configuration['guard']);

        return $configuration;
    }

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

        return parent::where(...func_get_args());
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
