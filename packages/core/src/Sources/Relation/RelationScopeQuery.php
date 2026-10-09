<?php

declare(strict_types=1);

namespace AzGuard\Sources\Relation;

use AzGuard\Exceptions\InvalidSourceContributionException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A predicate builder for trusted host callbacks, with terminal operations disabled.
 * This is not a sandbox for arbitrary PHP or separately obtained query/model objects.
 *
 * @extends Builder<Model>
 */
final class RelationScopeQuery extends Builder
{
    /** @param Builder<Model> $query */
    public static function forPredicates(Builder $query): self
    {
        $raw = $query->getQuery();

        return (new self(new RelationPredicateQuery($raw->connection, $raw->grammar, $raw->processor)))
            ->setModel(clone $query->getModel());
    }

    /** @param mixed $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and'): static
    {
        if ($column instanceof Closure && $operator === null) {
            $nested = self::forPredicates($this);
            $column($nested);
            $this->getQuery()->addNestedWhereQuery($nested->getQuery(), $boolean);

            return $this;
        }

        parent::where($column, $operator, $value, $boolean);

        return $this;
    }

    /** @param string|Relation<Model, Model, mixed> $relation
     * @param  mixed  $operator
     * @param  mixed  $count
     * @param  mixed  $boolean
     */
    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null): static
    {
        return parent::has($relation, $operator, $count, $boolean, $callback instanceof Closure ? static function (Builder $nested) use ($callback): void {
            $narrow = self::forPredicates($nested);
            $before = self::structure($narrow);
            $callback($narrow);

            if (self::structure($narrow) !== $before) {
                throw new InvalidSourceContributionException('RelationSource nested scope callback may only narrow WHERE predicates.');
            }
            $nested->getQuery()->addNestedWhereQuery($narrow->getQuery());
        } : null);
    }

    /** @param array<string, mixed> $attributes */
    public function newModelInstance($attributes = []): never
    {
        $this->refuse();
    }

    /** @param array<string>|string $columns */
    public function getModels($columns = ['*']): never
    {
        $this->refuse();
    }

    public function cursor(): never
    {
        $this->refuse();
    }

    /** @param string $column
     * @param  string|null  $key
     */
    public function pluck($column, $key = null): never
    {
        $this->refuse();
    }

    /** @param mixed $perPage
     * @param  mixed  $columns
     * @param  mixed  $pageName
     * @param  mixed  $page
     * @param  mixed  $total
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null): never
    {
        $this->refuse();
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): never
    {
        $this->refuse();
    }

    /** @param array<mixed> $values
     * @param  mixed  $uniqueBy
     * @param  mixed  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        $this->refuse();
    }

    /** @param mixed $column
     * @param  mixed  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): never
    {
        $this->refuse();
    }

    /** @param mixed $column
     * @param  mixed  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): never
    {
        $this->refuse();
    }

    /** @param array<string, mixed> $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): never
    {
        $this->refuse();
    }

    /** @param array<string, mixed> $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): never
    {
        $this->refuse();
    }

    public function delete(): never
    {
        $this->refuse();
    }

    public function forceDelete(): never
    {
        $this->refuse();
    }

    /** @param string $method
     * @param  array<mixed>  $parameters
     */
    public function __call($method, $parameters): mixed
    {
        if (! str_starts_with(strtolower($method), 'where') && ! str_starts_with(strtolower($method), 'orwhere')
            && ! in_array(strtolower($method), ['tosql', 'getbindings', 'getrawbindings', 'addwhereexistsquery', 'addwherecountquery'], true)) {
            $this->refuse();
        }

        return parent::__call($method, $parameters);
    }

    /** Structural changes never escape the isolated narrowing group.
     * @param  Builder<Model>  $query
     * @return array<string, mixed>
     */
    public static function structure(Builder $query): array
    {
        $state = get_object_vars($query->getQuery());
        unset($state['wheres']);
        $bindings = $query->getQuery()->getRawBindings();
        $bindings['where'] = [];
        $state['bindings'] = $bindings;
        $state['model'] = $query->getModel()::class;
        $state['model_table'] = $query->getModel()->getTable();
        $state['model_connection'] = $query->getModel()->getConnectionName();
        $state['eager'] = $query->getEagerLoads();
        $state['removed_scopes'] = $query->removedScopes();

        return $state;
    }

    private function refuse(): never
    {
        throw new InvalidSourceContributionException('RelationSource scope callback accepts grouped WHERE predicates only; terminal reads and writes are forbidden.');
    }
}
