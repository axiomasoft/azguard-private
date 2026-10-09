<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;

/**
 * Revision of a consumed authority snapshot.
 *
 * Contract: `state()` changes whenever the grants this source returns change, and only then; it is cheap, has no
 * side effects and may be called before and after each read. The engine reads it around every read of the source,
 * at most three times, then fails the decision with `consistency_error`; only the source read repeats. The token is
 * opaque (no subject revision), so cached sets of the source are keyed by the whole token.
 *
 * @spi
 */
interface FencesReads extends Source
{
    public function state(Panel $panel, TenantRef $tenant): StateToken;
}
