<?php

declare(strict_types=1);

namespace AzGuard\Http\Middleware;

use AzGuard\Panels\PanelResolver;
use AzGuard\Permissions\PermissionKey;
use BackedEnum;
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
 * Or via the static constructor (enum-aware, no manual string DSL):
 *
 *   Route::get('/export', ExportController::class)
 *       ->middleware(CheckDirectGrant::using(DocumentsPermission::Export, 'app'));
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
     * Build the `azguard.grant:...` middleware definition string.
     *
     * @param  string|BackedEnum  $permission  Permission key
     * @param  string|BackedEnum|null  $panelId  Panel ID (optional)
     */
    public static function using(string|BackedEnum $permission, string|BackedEnum|null $panelId = null): string
    {
        $args = [PermissionKey::normalize($permission)];

        if ($panelId !== null) {
            $args[] = PanelResolver::normalizeId($panelId);
        }

        return self::class.':'.implode(',', $args);
    }

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
