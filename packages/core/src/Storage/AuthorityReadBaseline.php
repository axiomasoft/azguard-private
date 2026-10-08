<?php

declare(strict_types=1);

namespace AzGuard\Storage;

/**
 * An explicitly installed host adapter for an isolated authority read baseline.
 *
 * @internal
 */
interface AuthorityReadBaseline
{
    /** A unique cache namespace while the exact baseline is active; null otherwise. */
    public function identity(Storage $storage): ?string;
}
