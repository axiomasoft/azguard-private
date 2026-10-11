<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Attributes\Api;

/**
 * `Applied` when the operation changed at least one grant; `Unchanged` for a repeat without a difference.
 *
 * @closed
 */
#[Api(since: '0.7')]
enum ChangeStatus: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
}
