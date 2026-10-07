<?php

declare(strict_types=1);

namespace AzGuard\Changes;

/**
 * `Applied` when the operation changed at least one grant; `Unchanged` for a repeat without a difference.
 *
 * @api
 */
enum ChangeStatus: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
}
