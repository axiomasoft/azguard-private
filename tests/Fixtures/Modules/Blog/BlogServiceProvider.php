<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog;

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\PanelBuilder;
use Illuminate\Support\ServiceProvider;

/**
 * Extends the panel the application chose. The panel id comes from the module's configuration.
 */
final class BlogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        AzGuard::configurePanel(
            config('blog.azguard_panel', 'admin'),
            static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([BlogAccessPlugin::make()]),
        );
    }
}
