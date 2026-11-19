<?php

declare(strict_types=1);

namespace AzGuard\Storage\Internal;

use Packman\Attributes\Internal;

/**
 * An explicitly installed host adapter for an isolated authority read baseline.
 *
 * @internal
 */
#[Internal]
interface AuthorityReadBaseline
{
    /** A unique cache namespace while the exact baseline is active; null otherwise. */
    public function identity(Storage $storage): ?string;
}
