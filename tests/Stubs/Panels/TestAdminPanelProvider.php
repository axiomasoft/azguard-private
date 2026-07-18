<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Panels;

use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelProvider;

final class TestAdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(id: 'admin')
            ->path(path: 'admin');
    }
}
