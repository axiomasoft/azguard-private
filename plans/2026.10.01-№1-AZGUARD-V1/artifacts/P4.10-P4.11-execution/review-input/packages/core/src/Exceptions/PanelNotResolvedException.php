<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The panel selection rule found no panel: nothing names one, the request has none and the model has no default.
 */
final class PanelNotResolvedException extends DefinitionException
{
    public function code(): string
    {
        return 'panel_not_resolved';
    }
}
