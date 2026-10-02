<?php

declare(strict_types=1);

namespace AzGuard\Plugins;

/**
 * What a plugin is told about the build it takes part in: the panel, its own id, the build and its declared
 * dependencies.
 *
 * It carries no options, no container and no panel object: a plugin keeps its settings in its own typed fields.
 *
 * @api
 */
final readonly class PluginContext
{
    /**
     * @internal built by the panel compiler
     *
     * @param  list<string>  $dependencies
     */
    public function __construct(
        private string $panelId,
        private string $pluginId,
        private string $buildId,
        private array $dependencies,
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

    /**
     * @return list<string> ids of the plugins this plugin requires on the panel
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }
}
