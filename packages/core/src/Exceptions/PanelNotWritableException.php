<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The panel has no writer that the change pipeline can use.
 */
#[Api(since: '0.7')]
final class PanelNotWritableException extends ChangeException
{
    public function code(): string
    {
        return 'panel_not_writable';
    }
}
