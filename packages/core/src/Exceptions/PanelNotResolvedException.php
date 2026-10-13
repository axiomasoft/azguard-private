<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The panel selection rule found no panel: nothing names one, the request has none and the model has no default.
 */
#[Api(since: '1.0')]
final class PanelNotResolvedException extends DefinitionException
{
    public function code(): string
    {
        return 'panel_not_resolved';
    }
}
