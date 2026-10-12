<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels\Options;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

/** A plugin that contributes one `LimitOptions` object. */
final class OptionsPlugin implements Plugin
{
    public function __construct(private readonly string $id, private readonly int $limit) {}

    public function id(): string
    {
        return $this->id;
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->options(new LimitOptions($this->limit));
    }
}
