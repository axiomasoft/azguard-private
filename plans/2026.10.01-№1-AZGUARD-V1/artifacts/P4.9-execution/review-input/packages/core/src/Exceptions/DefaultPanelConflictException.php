<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Two panels are declared as the default panel of the same subject model.
 */
final class DefaultPanelConflictException extends DefinitionException
{
    public function code(): string
    {
        return 'default_panel_conflict';
    }
}
