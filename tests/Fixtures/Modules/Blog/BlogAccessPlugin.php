<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog;

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;

/**
 * Plugin of the Blog module. It contributes the module's source as declared, without a key prefix.
 */
final class BlogAccessPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'blog/access';
    }

    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $panel->permissions([new BlogPostsSource]);
    }
}
