<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Explicit panel signals of one check name different panels.
 */
final class ConflictingPanelException extends DefinitionException
{
    public function code(): string
    {
        return 'conflicting_panel';
    }
}
