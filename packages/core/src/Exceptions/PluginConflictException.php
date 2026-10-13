<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * Two plugins of one panel set the same setting to different values and the panel provider does not decide.
 */
#[Api(since: '1.0')]
final class PluginConflictException extends PluginException
{
    public function code(): string
    {
        return 'plugin_conflict';
    }
}
