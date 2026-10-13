<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * Explicit panel signals of one check name different panels.
 */
#[Api(since: '1.0')]
final class ConflictingPanelException extends DefinitionException
{
    public function code(): string
    {
        return 'conflicting_panel';
    }
}
