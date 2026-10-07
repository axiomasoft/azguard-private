<?php

declare(strict_types=1);

namespace AzGuard\Storage\Concerns;

use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Storage\WriteGuardedBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/** Direct Eloquent writes are forbidden; raw SQL via toBase()/getQuery() is outside this contract. */
trait GuardsDirectWrites
{
    /** Reject destruction before Eloquent performs its preliminary read.
     * @param  Collection<array-key, mixed>|array<mixed>|int|string  $ids
     */
    final public static function destroy(mixed $ids): never
    {
        throw new UnsupportedDirectWriteException(static::class.' cannot be written directly; use Storage::mutate() through the change pipeline.');
    }

    /** @param QueryBuilder $query
     * @return Builder<$this>
     */
    final public function newEloquentBuilder($query): Builder
    {
        return new WriteGuardedBuilder($query, $this);
    }

    /** @param array<mixed> $options */
    final public function save(array $options = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $options */
    final public function saveQuietly(array $options = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $options */
    final public function saveOrFail(array $options = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $options
     * @param array<mixed>|string|null $uniqueBy */
    final public function saveOrIgnore(array $options = [], mixed $uniqueBy = null): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes
     * @param array<mixed> $options */
    final public function update(array $attributes = [], array $options = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes
     * @param array<mixed> $options */
    final public function updateQuietly(array $attributes = [], array $options = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes
     * @param array<mixed> $options */
    final public function updateOrFail(array $attributes = [], array $options = []): never
    {
        $this->rejectDirectWrite();
    }

    final public function push(): never
    {
        $this->rejectDirectWrite();
    }

    final public function pushQuietly(): never
    {
        $this->rejectDirectWrite();
    }

    final public function delete(): never
    {
        $this->rejectDirectWrite();
    }

    final public function deleteQuietly(): never
    {
        $this->rejectDirectWrite();
    }

    final public function deleteOrFail(): never
    {
        $this->rejectDirectWrite();
    }

    final public function forceDelete(): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed>|string|null $attribute */
    final public function touch(mixed $attribute = null): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed>|string|null $attribute */
    final public function touchQuietly(mixed $attribute = null): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $extra */
    final public function increment(mixed $column, mixed $amount = 1, array $extra = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $extra */
    final public function decrement(mixed $column, mixed $amount = 1, array $extra = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $extra */
    final public function incrementQuietly(mixed $column, mixed $amount = 1, array $extra = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $extra */
    final public function decrementQuietly(mixed $column, mixed $amount = 1, array $extra = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $columns
     * @param array<mixed> $extra */
    final public function incrementEach(array $columns, array $extra = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $columns
     * @param array<mixed> $extra */
    final public function decrementEach(array $columns, array $extra = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $columns
     * @param array<mixed> $extra */
    final public function incrementEachQuietly(array $columns, array $extra = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $columns
     * @param array<mixed> $extra */
    final public function decrementEachQuietly(array $columns, array $extra = []): never
    {
        $this->rejectDirectWrite();
    }

    private function rejectDirectWrite(): never
    {
        throw new UnsupportedDirectWriteException($this::class.' cannot be written directly; use Storage::mutate() through the change pipeline.');
    }
}
