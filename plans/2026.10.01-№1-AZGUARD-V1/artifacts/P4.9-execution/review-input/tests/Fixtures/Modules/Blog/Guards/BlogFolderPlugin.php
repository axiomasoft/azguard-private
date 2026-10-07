<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog\Guards;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

/**
 * Blog module folder. discover(__DIR__) adds it; the plugin does not prefix keys.
 */
final class BlogFolderPlugin extends BasePlugin
{
    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'blog/folder';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->discover(__DIR__);
    }
}
