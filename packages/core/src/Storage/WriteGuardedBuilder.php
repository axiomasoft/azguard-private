<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Exceptions\UnsupportedDirectWriteException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** Direct Eloquent writes are forbidden; raw SQL via toBase()/getQuery() is outside this contract.
 * @template TModel of Model
 *
 * @extends Builder<TModel> */
final class WriteGuardedBuilder extends Builder
{
    /** @param TModel $model */
    public function __construct(QueryBuilder $query, Model $model)
    {
        parent::__construct($query);
        $this->setModel($model);
    }

    /** @param array<mixed> $values */
    final public function insert(array $values): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $values */
    final public function insertOrIgnore(array $values): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $values
     * @param array<mixed> $returning
     * @param array<mixed>|string|null $uniqueBy */
    final public function insertOrIgnoreReturning(array $values, array $returning = ['*'], mixed $uniqueBy = null): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $values */
    final public function insertGetId(array $values, mixed $sequence = null): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $columns */
    final public function insertUsing(array $columns, mixed $query): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $columns */
    final public function insertOrIgnoreUsing(array $columns, mixed $query): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $values
     * @param array<mixed>|string $uniqueBy
     * @param array<mixed>|null $update */
    final public function upsert(array $values, mixed $uniqueBy, mixed $update = null): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $values */
    final public function update(array $values): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $values */
    final public function updateFrom(array $values): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes */
    final public function updateOrInsert(array $attributes, mixed $values = []): never
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

    final public function delete(): never
    {
        $this->rejectDirectWrite();
    }

    final public function forceDelete(): never
    {
        $this->rejectDirectWrite();
    }

    final public function truncate(): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes */
    final public function create(array $attributes = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes */
    final public function forceCreate(array $attributes): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes */
    final public function firstOrCreate(array $attributes = [], mixed $values = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes */
    final public function updateOrCreate(array $attributes, mixed $values = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes */
    final public function createOrFirst(array $attributes = [], mixed $values = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes */
    final public function createQuietly(array $attributes = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes */
    final public function forceCreateQuietly(array $attributes = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed> $attributes
     * @param array<mixed> $extra */
    final public function incrementOrCreate(array $attributes, string $column = 'count', mixed $default = 1, mixed $step = 1, array $extra = []): never
    {
        $this->rejectDirectWrite();
    }

    /** @param array<mixed>|string|null $column */
    final public function touch(mixed $column = null): never
    {
        $this->rejectDirectWrite();
    }

    private function rejectDirectWrite(): never
    {
        throw new UnsupportedDirectWriteException($this->getModel()::class.' cannot be written directly; use Storage::mutate() through the change pipeline.');
    }
}
