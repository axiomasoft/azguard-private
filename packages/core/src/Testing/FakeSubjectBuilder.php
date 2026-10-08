<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal The query of {@see FakeSubject}: it finds the subject of the key it was asked for and reads no table.
 *
 * @extends Builder<FakeSubject>
 */
final class FakeSubjectBuilder extends Builder
{
    private int|string|null $key = null;

    /** @param  mixed  $id */
    public function whereKey($id): static
    {
        $this->key = is_int($id) || is_string($id) ? $id : null;

        return $this;
    }

    /** @param  array<int, string>|string  $columns */
    public function first($columns = ['*']): ?Model
    {
        return $this->key === null ? null : FakeSubject::of($this->key);
    }
}
