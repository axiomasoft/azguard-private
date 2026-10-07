<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Contracts\Plugins\DependsOnPlugins;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use Closure;

/**
 * A plugin driven by the test: it writes every lifecycle call to a shared log and runs the given callbacks.
 */
final class ProbePlugin implements DependsOnPlugins, Plugin
{
    /** @var list<string> `register:<panel>:<plugin>` and `boot:<panel>:<plugin>` in the order they ran */
    public static array $log = [];

    /**
     * @param  array<mixed>  $requires
     * @param  (Closure(PanelBuilder, PluginContext): mixed)|null  $register
     * @param  (Closure(Panel, PluginContext): mixed)|null  $boot
     */
    private function __construct(
        private readonly string $id,
        private readonly array $requires,
        private readonly ?Closure $register,
        private readonly ?Closure $boot,
    ) {}

    /**
     * @param  array<mixed>  $requires
     * @param  (Closure(PanelBuilder, PluginContext): mixed)|null  $register
     * @param  (Closure(Panel, PluginContext): mixed)|null  $boot
     */
    public static function make(string $id, array $requires = [], ?Closure $register = null, ?Closure $boot = null): self
    {
        return new self($id, $requires, $register, $boot);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function requires(): array
    {
        return $this->requires;
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        self::$log[] = 'register:'.$context->panelId().':'.$context->pluginId();

        ($this->register ?? static fn (): null => null)($panel, $context);
    }

    public function boot(Panel $panel, PluginContext $context): void
    {
        self::$log[] = 'boot:'.$panel->id().':'.$context->pluginId();

        ($this->boot ?? static fn (): null => null)($panel, $context);
    }
}
