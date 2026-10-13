<?php

declare(strict_types=1);

namespace AzGuard\Plugins;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;

/**
 * What a plugin is told about the build it takes part in: the panel, its own id and the build.
 *
 * It carries no options, no container and no panel object: a plugin keeps its settings in its own typed fields.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class PluginContext
{
    /**
     * @internal built by the panel compiler
     */
    public function __construct(
        private string $panelId,
        private string $pluginId,
        private string $buildId,
    ) {}

    public function panelId(): string
    {
        return $this->panelId;
    }

    public function pluginId(): string
    {
        return $this->pluginId;
    }

    /**
     * Id of the deployed build of the application; it changes when the code or the configuration changes.
     */
    public function buildId(): string
    {
        return $this->buildId;
    }
}
