<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use RuntimeException;

/** A plugin that remembers the first panel it registered on and refuses a second one. */
final class OnePanelPlugin implements Plugin
{
    public static ?string $panel = null;

    public function id(): string
    {
        return 'acme/one-panel';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        if (self::$panel !== null && self::$panel !== $context->panelId()) {
            throw new RuntimeException('The plugin already works on panel '.self::$panel.'.');
        }
        self::$panel = $context->panelId();
    }
}
