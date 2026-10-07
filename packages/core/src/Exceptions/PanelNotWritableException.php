<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The panel has no writer that the change pipeline can use.
 */
final class PanelNotWritableException extends ChangeException
{
    public function code(): string
    {
        return 'panel_not_writable';
    }
}
