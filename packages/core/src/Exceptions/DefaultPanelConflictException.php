<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * Two panels are declared as the default panel of the same subject model.
 */
#[Api(since: '0.7')]
final class DefaultPanelConflictException extends DefinitionException
{
    public function code(): string
    {
        return 'default_panel_conflict';
    }
}
