<?php

declare(strict_types=1);

namespace AzGuard\Panels;

/**
 * How often a panel re-reads the state version that invalidates cached permissions.
 *
 * @api
 */
enum StateRefresh: string
{
    /** Once per request or job. */
    case Request = 'request';

    /** Before every check. */
    case Check = 'check';
}
