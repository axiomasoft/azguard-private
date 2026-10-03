<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Blog;

use AzGuard\Facades\AzGuard;
use Illuminate\Support\ServiceProvider;

/**
 * Brings the Blog module's own panel through the facade while the application is still registering providers.
 */
final class BlogModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        AzGuard::registerPanel(BlogGuardPanelProvider::class);
    }
}
