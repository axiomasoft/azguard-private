<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Two plugins of one panel set the same setting to different values and the panel provider does not decide.
 */
final class PluginConflictException extends PluginException
{
    public function code(): string
    {
        return 'plugin_conflict';
    }
}
