<?php

declare(strict_types=1);

namespace AzGuard\Http\Middleware;

use AzGuard\Panels\PanelResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: checks that the authenticated user has a direct grant.
 *
 * Usage in routes:
 *
 *   Route::get('/export', ExportController::class)
 *       ->middleware('azguard.grant:app.documents.export,app');
 *
 * If the second argument (panel) is omitted, the current AzGuard panel is used.
 *
 * Responses:
 *   401 — user is not authenticated
 *   403 — grant is absent or expired
 */
final class CheckDirectGrant
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $permissionKey  Permission key
     * @param  string|null  $panelId  Panel ID (optional)
     */
    public function handle(
        Request $request,
        Closure $next,
        string $permissionKey,
        ?string $panelId = null,
    ): Response {
        abort_if(
            boolean: ! $request->user(),
            code: Response::HTTP_UNAUTHORIZED,
            message: 'Unauthenticated.',
        );

        $resolvedPanel = PanelResolver::resolve($panelId);
        $user = $request->user();

        $hasGrant = method_exists($user, 'hasGrant')
            ? $user->hasGrant($permissionKey, $resolvedPanel)
            : false;

        abort_if(
            boolean: ! $hasGrant,
            code: Response::HTTP_FORBIDDEN,
            message: "Direct grant [{$permissionKey}] is required for this action.",
        );

        return $next($request);
    }
}
