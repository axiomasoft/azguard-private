<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Models\DirectGrant;

class CustomDirectGrant extends DirectGrant
{
    public static bool $created = false;

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('custom_only', static function ($query): void {
            $query->where('permission_key', 'like', 'custom.%');
        });

        static::created(static function (): void {
            self::$created = true;
        });
    }
}
