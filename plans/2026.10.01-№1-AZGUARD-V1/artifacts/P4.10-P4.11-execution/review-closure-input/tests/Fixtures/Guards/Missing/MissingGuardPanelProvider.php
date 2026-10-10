<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Missing;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;

final class MissingGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'missing';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->resourcePrefix(false);
    }
}
