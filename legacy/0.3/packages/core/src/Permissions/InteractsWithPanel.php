<?php

declare(strict_types=1);

namespace AzGuard\Permissions;

use AzGuard\Panels\Panel;
use BackedEnum;

trait InteractsWithPanel
{
    public function resolve(Panel $panel): string
    {
        if (! $this instanceof BackedEnum) {
            return '';
        }

        return $panel->resolvePermission(permission: $this);
    }
}
