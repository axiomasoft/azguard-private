<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\Panel as CompiledPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use Closure;

/**
 * A plugin driven by the test: it writes every call of the package to a shared log and runs the given callback.
 *
 * It also declares a `boot()` the package does not know: a call of it would show in the log as `boot:...`.
 */
final class ProbePlugin implements Plugin
{
    /** @var list<string> `register:<panel>:<plugin>` and `boot:<panel>:<plugin>` in the order they ran */
    public static array $log = [];

    /**
     * @param  (Closure(PanelBuilder, PluginContext): mixed)|null  $register
     */
    private function __construct(
        private readonly string $id,
        private readonly ?Closure $register,
    ) {}

    /**
     * @param  (Closure(PanelBuilder, PluginContext): mixed)|null  $register
     */
    public static function make(string $id, ?Closure $register = null): self
    {
        return new self($id, $register);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        self::$log[] = 'register:'.$context->panelId().':'.$context->pluginId();

        ($this->register ?? static fn (): null => null)($panel, $context);
    }

    public function boot(CompiledPanel $panel, PluginContext $context): void
    {
        self::$log[] = 'boot:'.$panel->id().':'.$context->pluginId();
    }
}
