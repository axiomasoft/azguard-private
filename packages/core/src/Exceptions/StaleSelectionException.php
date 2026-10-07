<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The selected grant or build changed after it was read; the change is not applied.
 */
final class StaleSelectionException extends ChangeException
{
    public function code(): string
    {
        return 'stale_selection';
    }
}
