<?php

declare(strict_types=1);

namespace AzGuard\Http\Middleware;

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\PanelResolver;
use BackedEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetCurrentPanel
{
    /**
     * Build the `azguard.panel:...` middleware definition string.
     */
    public static function using(string|BackedEnum $panelId): string
    {
        return self::class.':'.PanelResolver::normalizeId($panelId);
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = AzGuard::panel(id: $panelId);

        if ($panel === null) {
            abort(response("AzGuard panel [{$panelId}] is not registered.", 500));
        }

        AzGuard::setCurrentPanel(panel: $panel);

        try {
            return $next($request);
        } finally {
            AzGuard::setCurrentPanel(panel: null);
        }
    }
}
