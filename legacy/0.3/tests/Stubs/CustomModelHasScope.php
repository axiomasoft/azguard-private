<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Models\ModelHasScope;

class CustomModelHasScope extends ModelHasScope
{
    public static bool $queried = false;

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('observable', static function ($builder): void {
            self::$queried = true;
        });
    }
}
