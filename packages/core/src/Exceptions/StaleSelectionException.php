<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The selected grant or build changed after it was read; the change is not applied.
 */
#[Api(since: '1.0')]
final class StaleSelectionException extends ChangeException
{
    public function code(): string
    {
        return 'stale_selection';
    }
}
