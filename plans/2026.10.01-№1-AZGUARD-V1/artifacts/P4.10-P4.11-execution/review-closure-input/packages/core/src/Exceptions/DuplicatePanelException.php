<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A panel id is registered twice; an intended replacement uses `replace()`.
 */
final class DuplicatePanelException extends DefinitionException
{
    public function code(): string
    {
        return 'duplicate_panel';
    }
}
