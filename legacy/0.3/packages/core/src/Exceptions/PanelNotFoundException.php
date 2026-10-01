<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown when an AzGuard panel ID is referenced but not registered.
 *
 * Resolution:
 *   - Ensure the panel is registered via AzGuard::registerPanel()
 *     (typically in a ServiceProvider or AppServiceProvider::boot()).
 *   - Check for typos in the panel ID string.
 */
final class PanelNotFoundException extends AzGuardException
{
    public function __construct(public readonly string $panelId)
    {
        parent::__construct(
            "AzGuard panel [{$panelId}] is not registered. "
            .'Register it via AzGuard::registerPanel() before use.',
        );
    }
}
