<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Query;

use RuntimeException;

/** Shared across cloned and nested predicate builders, including caught terminal attempts. */
final class QueryGuard
{
    private bool $rejected = false;

    public function reject(): never
    {
        $this->rejected = true;

        throw new RuntimeException('Assignment-scope filters may only construct predicates.');
    }

    public function validate(): void
    {
        if ($this->rejected) {
            $this->reject();
        }
    }
}
