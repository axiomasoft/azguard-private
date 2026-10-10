<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Binding;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Orders\OrderPermission;

final class BindingGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'binding';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->resourcePrefix(false)
            ->permissions([OrderPermission::class])
            ->policies([PolicyBinding::for(OrderPermission::View, SilentPolicy::class)]);
    }
}
