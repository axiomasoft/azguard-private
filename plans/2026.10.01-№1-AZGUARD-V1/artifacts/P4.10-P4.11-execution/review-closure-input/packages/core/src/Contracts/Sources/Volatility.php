<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

/**
 * How long a source may reuse the grants it read.
 *
 * @spi
 */
enum Volatility: string
{
    /** The source changes with a revision the panel already accounts for. */
    case Stable = 'stable';

    /** Reuse the read for the rest of the request or job. */
    case Request = 'request';

    /** Read again on every check. */
    case Volatile = 'volatile';
}
