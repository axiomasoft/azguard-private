<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Plugins;

use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

/**
 * A ready set of additions to a panel: sources, permissions, roles, restrictions, pipes and health checks attached
 * with one call.
 *
 * A plugin takes its settings through its own constructor or named factory with typed parameters. Every panel
 * works with its own copy of the plugin, so one plugin can be attached to several panels.
 *
 * @spi
 */
interface Plugin
{
    /**
     * Stable id of the plugin such as `acme/audit-trail`; a panel never has two plugins with one id.
     */
    public function id(): string;

    /**
     * Adds to the panel; runs once per panel in the order the plugins were attached.
     */
    public function register(PanelBuilder $panel, PluginContext $context): void;

    /**
     * Runs once per panel when every panel is compiled; the panel can no longer be changed here.
     */
    public function boot(Panel $panel, PluginContext $context): void;
}
