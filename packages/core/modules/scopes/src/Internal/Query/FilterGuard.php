<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal\Query;

use RuntimeException;

/**
 * The guard of one {@see FilterQuery} group: shared across its cloned and nested builders, so a terminal or
 * destructive call the filter caught still fails the group when it is validated.
 *
 * @internal
 */
final class FilterGuard
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
