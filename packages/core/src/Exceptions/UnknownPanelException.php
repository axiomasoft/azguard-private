<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * No panel is registered under the id.
 */
final class UnknownPanelException extends DefinitionException
{
    public function code(): string
    {
        return 'unknown_panel';
    }
}
