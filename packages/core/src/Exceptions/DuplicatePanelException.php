<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * A panel id is registered twice; an intended replacement uses `replace()`.
 */
#[Api(since: '0.7')]
final class DuplicatePanelException extends DefinitionException
{
    public function code(): string
    {
        return 'duplicate_panel';
    }
}
