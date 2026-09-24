<?php

declare(strict_types=1);

namespace AzGuard\Runtime;

use AzGuard\Panels\Panel;

/**
 * Request/job-scoped current panel. Bound as `scoped` so HTTP/Octane/queue
 * workers get a fresh holder; the singleton manager keeps only the registry.
 *
 * @internal
 */
final class CurrentPanelState
{
    public ?Panel $panel = null;
}
