<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\Panel as CompiledPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

/**
 * A plugin that declares a `boot()` the package no longer calls: it would change the panel through the kept builder.
 */
final class LateChangePlugin implements Plugin
{
    /** @var int how many times the package called `boot()`: never */
    public static int $boots = 0;

    private ?PanelBuilder $builder = null;

    public function id(): string
    {
        return 'acme/late-change';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $this->builder = $panel;
    }

    public function boot(CompiledPanel $panel, PluginContext $context): void
    {
        self::$boots++;
        $this->builder?->label('Changed after the boot');
    }
}
