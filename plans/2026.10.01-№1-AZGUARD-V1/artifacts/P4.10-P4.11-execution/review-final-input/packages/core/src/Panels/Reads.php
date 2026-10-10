<?php

declare(strict_types=1);

namespace AzGuard\Panels;

/**
 * Which database connection decisions read grants from.
 *
 * @api
 */
enum Reads: string
{
    /** The write PDO outside an unrecognized transaction; decisions do not see replica lag. */
    case Primary = 'primary';

    /**
     * The configured read PDO, pinned throughout the authority fence without sticky/write fallback.
     * Replica lag is permitted: visibility follows the host's replica delay, without a package bound.
     * An unsplit connection uses its single configured PDO. Unrecognized transactions are rejected.
     */
    case Default = 'default';
}
