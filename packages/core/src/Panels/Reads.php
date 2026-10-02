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
    /** Always the write connection, so a decision never sees a replica that lags behind a change. */
    case Primary = 'primary';

    /** Whatever connection Laravel picks, read replicas included. */
    case Default = 'default';
}
