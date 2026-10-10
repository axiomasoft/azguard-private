<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

/**
 * A plugin that sets one scalar setting of the panel.
 */
final class CacheTtlPlugin extends BasePlugin
{
    private function __construct(private readonly string $id, private readonly int $ttl) {}

    public static function make(string $id, int $ttl): self
    {
        return new self($id, $ttl);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->cache(ttl: $this->ttl);
    }
}
