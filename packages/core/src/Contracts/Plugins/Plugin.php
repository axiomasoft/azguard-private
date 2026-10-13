<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Plugins;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

/**
 * A ready set of additions to a panel: sources, permissions, roles, restrictions, pipes and health checks attached
 * with one call.
 *
 * A plugin takes its settings through its own constructor or named factory with typed parameters. Every panel
 * works with its own copy of the plugin, so one plugin can be attached to several panels.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface Plugin
{
    /**
     * Stable id of the plugin such as `acme/audit-trail`; a panel never has two plugins with one id. The prefix
     * `azguard/` is reserved for the classes of this package.
     */
    public function id(): string;

    /**
     * Declares contributions only, through the panel builder: no database or network I/O, no current user, tenant,
     * request or clock, no global listener. Runs once per panel in the order the plugins were attached.
     */
    public function register(PanelBuilder $panel, PluginContext $context): void;
}
