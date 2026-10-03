<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Modules\Shop;

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\PanelBuilder;
use Illuminate\Support\ServiceProvider;

/**
 * Extends the panel the application chose for the Shop module.
 *
 * The panel id comes from `shop.azguard_panel`. The permission name is the module's own `shop.posts.edit` unless
 * `shop.permission` names another one, which is how a test shows two modules declaring one name.
 */
final class ShopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configured = config('shop.permission', 'shop.posts.edit');
        $permission = is_string($configured) && $configured !== '' ? $configured : 'shop.posts.edit';

        AzGuard::configurePanel(
            config('shop.azguard_panel', 'admin'),
            static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ShopAccessPlugin::make($permission)]),
        );
    }
}
