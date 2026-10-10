<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Tests\Fixtures\Panels\FixturePanel;

final class BetaPanel extends FixturePanel
{
    public static function getId(): string
    {
        return 'beta';
    }
}
