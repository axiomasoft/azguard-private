<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * A permission enum belongs to several panels and the check names none of them.
 */
#[Api(since: '1.0')]
final class AmbiguousPanelException extends DefinitionException
{
    public function code(): string
    {
        return 'ambiguous_panel';
    }
}
