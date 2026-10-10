<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Both;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;

final class BothGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'both';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->resourcePrefix(false);
    }
}
