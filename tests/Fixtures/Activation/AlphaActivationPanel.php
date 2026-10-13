<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Activation;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;

final class AlphaActivationPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'alpha';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return ActivationWorld::describe($panel, 'alpha');
    }
}
