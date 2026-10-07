<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

final class TestPanel extends FixturePanel
{
    public static function getId(): string
    {
        return 'test';
    }
}
