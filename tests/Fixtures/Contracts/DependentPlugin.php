<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Contracts\Plugins\DependsOnPlugins;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;
use RuntimeException;

final class DependentPlugin extends BasePlugin implements DependsOnPlugins
{
    private ?string $registered = null;

    private bool $booted = false;

    public function id(): string
    {
        return 'acme/dependent';
    }

    public function requires(): array
    {
        return ['acme/label'];
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $this->registered = $context->panelId();
    }

    public function boot(Panel $panel, PluginContext $context): void
    {
        if ($this->registered !== $panel->id() || $this->booted || $context->dependencies() !== $this->requires()
            || app(PanelRegistry::class)->get($panel->id()) !== $panel) {
            throw new RuntimeException('The contract stand must use the registered plugin, its dependencies and the active registry.');
        }
        $this->booted = true;
    }
}
