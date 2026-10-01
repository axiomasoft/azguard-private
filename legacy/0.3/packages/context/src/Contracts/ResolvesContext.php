<?php

declare(strict_types=1);

namespace AzGuard\Context\Contracts;

use AzGuard\Context\AuthorizationContext;
use Illuminate\Http\Request;

/**
 * Interface for classes able to extract an AuthorizationContext from a Request.
 *
 * Implemented in the application:
 *
 *   final class WorkspaceContextResolver implements ResolvesContext
 *   {
 *       public function resolve(Request $request): ?AuthorizationContext
 *       {
 *           $id = $request->route('workspace');
 *           return $id
 *               ? new AuthorizationContext('app', 'workspace', $id)
 *               : null;
 *       }
 *
 *       public function panelId(): string { return 'app'; }
 *   }
 *
 * Registered in a PanelProvider:
 *   AzGuardContext::registerResolver(WorkspaceContextResolver::class);
 */
interface ResolvesContext
{
    /**
     * Extract the context from the current request.
     * Return null if the context does not apply to this request.
     */
    public function resolve(Request $request): ?AuthorizationContext;

    /**
     * The panel this resolver works for.
     */
    public function panelId(): string;
}
