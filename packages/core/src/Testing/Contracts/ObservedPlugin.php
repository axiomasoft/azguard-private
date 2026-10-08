<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Contracts\Plugins\DependsOnPlugins;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use Closure;

/**
 * Observes the actual boot after registration, on the panel's own plugin copy.
 *
 * @internal
 */
final class ObservedPlugin implements DependsOnPlugins, Plugin
{
    /** @param Closure(Panel, Closure(): void): void $observe */
    public function __construct(private Plugin $plugin, private readonly Closure $observe) {}

    public function __clone(): void
    {
        $this->plugin = clone $this->plugin;
    }

    public function id(): string
    {
        return $this->plugin->id();
    }

    public function requires(): array
    {
        return $this->plugin instanceof DependsOnPlugins ? $this->plugin->requires() : [];
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $this->plugin->register($panel, $context);
    }

    public function boot(Panel $panel, PluginContext $context): void
    {
        ($this->observe)($panel, fn () => $this->plugin->boot($panel, $context));
    }
}
