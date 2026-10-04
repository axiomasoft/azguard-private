<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tests\Fixtures\Panels\User;

final class ShopPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'shop';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class)->resourcePrefix(false);
    }
}
