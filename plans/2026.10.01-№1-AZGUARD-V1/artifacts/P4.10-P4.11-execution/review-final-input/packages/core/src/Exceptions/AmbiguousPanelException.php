<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A permission enum belongs to several panels and the check names none of them.
 */
final class AmbiguousPanelException extends DefinitionException
{
    public function code(): string
    {
        return 'ambiguous_panel';
    }
}
