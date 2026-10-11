<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Attributes\Api;

/**
 * How often a panel re-reads the state version that invalidates cached permissions.
 *
 * @closed
 */
#[Api(since: '0.7')]
enum StateRefresh: string
{
    /** Once per request or job. */
    case Request = 'request';

    /** Before every check. */
    case Check = 'check';
}
