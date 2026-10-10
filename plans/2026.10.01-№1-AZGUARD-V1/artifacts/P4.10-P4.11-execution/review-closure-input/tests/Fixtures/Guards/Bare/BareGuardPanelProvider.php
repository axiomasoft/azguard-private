<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Bare;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;

final class BareGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'bare';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->resourcePrefix(false);
    }
}
