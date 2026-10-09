<?php

declare(strict_types=1);

namespace AzGuard\Sources\Relation;

use AzGuard\Exceptions\InvalidSourceContributionException;
use Illuminate\Database\Query\Builder;

/** SQL predicates without terminal query execution; nested subqueries retain this builder. */
final class RelationPredicateQuery extends Builder
{
    protected function runSelect(): never
    {
        $this->refuse();
    }

    public function exists(): never
    {
        $this->refuse();
    }

    public function cursor(): never
    {
        $this->refuse();
    }

    public function explain(): never
    {
        $this->refuse();
    }

    /**
     * @param  array<mixed>  $values
     */
    public function insert(array $values): never
    {
        $this->refuse();
    }

    /**
     * @param  array<mixed>  $values
     */
    public function insertOrIgnore(array $values): never
    {
        $this->refuse();
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<mixed>  $returning
     * @param  array<mixed>|string|null  $uniqueBy
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null): never
    {
        $this->refuse();
    }

    /**
     * @param  array<mixed>  $values
     * @param  mixed  $sequence
     */
    public function insertGetId(array $values, $sequence = null): never
    {
        $this->refuse();
    }

    /**
     * @param  array<mixed>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): never
    {
        $this->refuse();
    }

    /**
     * @param  array<mixed>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        $this->refuse();
    }

    /**
     * @param  array<mixed>  $values
     */
    public function update(array $values): never
    {
        $this->refuse();
    }

    /**
     * @param  array<mixed>  $values
     */
    public function updateFrom(array $values): never
    {
        $this->refuse();
    }

    /**
     * Laravel 11 declares $uniqueBy and $update untyped and Laravel 12+ narrows them; `mixed` is the
     * only parameter type compatible with every supported Builder, so the override loads on all three.
     *
     * @param  array<mixed>  $values
     */
    public function upsert(array $values, mixed $uniqueBy, mixed $update = null): never
    {
        $this->refuse();
    }

    /**
     * @param  mixed  $id
     */
    public function delete($id = null): never
    {
        $this->refuse();
    }

    public function truncate(): never
    {
        $this->refuse();
    }

    private function refuse(): never
    {
        throw new InvalidSourceContributionException('RelationSource scope callback accepts grouped WHERE predicates only; terminal reads and writes are forbidden.');
    }
}
