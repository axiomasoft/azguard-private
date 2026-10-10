<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Plugins;

/**
 * A plugin that works only next to other plugins of the same panel.
 *
 * @spi
 */
interface DependsOnPlugins
{
    /**
     * @return list<string> ids of the plugins that must be attached to the panel
     */
    public function requires(): array;
}
