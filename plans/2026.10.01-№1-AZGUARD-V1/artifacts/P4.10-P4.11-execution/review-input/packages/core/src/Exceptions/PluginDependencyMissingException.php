<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A plugin of a panel requires another plugin that is not attached to the panel.
 */
final class PluginDependencyMissingException extends PluginException
{
    public function code(): string
    {
        return 'plugin_dependency_missing';
    }
}
