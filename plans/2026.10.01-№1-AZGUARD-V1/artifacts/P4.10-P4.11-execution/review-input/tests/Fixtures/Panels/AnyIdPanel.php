<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

/**
 * A provider whose id is set by the test, for ids the grammar must reject.
 */
final class AnyIdPanel extends FixturePanel
{
    public static string $id = 'any';

    public static function getId(): string
    {
        return self::$id;
    }
}
