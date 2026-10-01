<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown when a panel ID is required but neither explicitly provided
 * nor available as the current AzGuard panel.
 *
 * Resolution: call ->on('panel-id') on the builder, or ensure
 * SetCurrentPanel middleware has run before this code path.
 */
final class PanelNotSetException extends AzGuardException
{
    public function __construct()
    {
        parent::__construct(
            'No panel ID provided and no current panel is set. '
            .'Call ->on("panel-id") or ensure SetCurrentPanel middleware has run.',
        );
    }
}
