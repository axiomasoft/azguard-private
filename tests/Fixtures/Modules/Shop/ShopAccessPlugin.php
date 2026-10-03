<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Shop;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;

/**
 * Plugin of the Shop module. `$local` lets a test declare the same permission name as another module.
 */
final class ShopAccessPlugin extends BasePlugin
{
    private function __construct(private readonly string $local) {}

    public static function make(string $local = 'shop.posts.edit'): self
    {
        return new self($local);
    }

    public function id(): string
    {
        return 'shop/access';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->permissions([new ShopPostsSource($this->local)]);
    }
}
