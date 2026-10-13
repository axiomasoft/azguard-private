<?php

declare(strict_types=1);

namespace AzGuard\Testing\Internal\Contracts;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use Closure;

/**
 * Observes the actual `register()` of the plugin under test, on the panel's own plugin copy.
 *
 * @internal
 */
final class ObservedPlugin implements Plugin
{
    /** @param Closure(PluginContext, Closure(): void): void $observe called around every `register()` of the plugin */
    public function __construct(private Plugin $plugin, private readonly Closure $observe) {}

    public function __clone(): void
    {
        $this->plugin = clone $this->plugin;
    }

    public function id(): string
    {
        return $this->plugin->id();
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        ($this->observe)($context, fn () => $this->plugin->register($panel, $context));
    }
}
