<?php

declare(strict_types=1);

namespace AzGuard\Plugins;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\Panel;

/**
 * Common base of plugins: the lifecycle only.
 *
 * The base declares no factory and no options: a plugin has its own constructor or `make(...)` with named typed
 * parameters, and its own `id()`.
 *
 * @spi
 */
abstract class BasePlugin implements Plugin
{
    public function boot(Panel $panel, PluginContext $context): void {}
}
