<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use AzGuard\Tests\Fixtures\Panels\FixturePanel;

final class PublicPanel extends FixturePanel
{
    public static function getId(): string
    {
        return 'crm-public';
    }
}
