<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Http;

use AzGuard\Panels\CurrentPanel;
use Closure;
use Illuminate\Http\Request;

/** Records the panel of the request each time it runs. */
final class TraceMiddleware
{
    /** @var list<?string> */
    public static array $panels = [];

    public function handle(Request $request, Closure $next): mixed
    {
        self::$panels[] = app(CurrentPanel::class)->get()?->id();

        return $next($request);
    }
}
