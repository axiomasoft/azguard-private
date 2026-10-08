<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http;

use AzGuard\Laravel\Http\Middleware\CheckPermission;
use Closure;
use Illuminate\Http\Request;

/** `azguard.can` that counts how many times a check runs, then runs the real middleware. */
final class CountingCheck
{
    public static int $runs = 0;

    public function handle(Request $request, Closure $next, string $permission, ?string $on = null): mixed
    {
        self::$runs++;

        return app(CheckPermission::class)->handle($request, $next, $permission, $on);
    }
}
