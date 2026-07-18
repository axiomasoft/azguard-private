<?php

declare(strict_types=1);

namespace AzGuard\Auth;

final class BladeHelper
{
    public static function authed(): bool
    {
        return auth()->check();
    }
}
