<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Ambiguous;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;

final class AmbiguousGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'ambiguous';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->resourcePrefix(false);
    }
}
