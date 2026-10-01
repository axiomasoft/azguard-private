<?php

declare(strict_types=1);

namespace AzGuard\Auth;

use AzGuard\Facades\AzGuard;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Gate policy for checking direct grants via the Laravel Gate.
 *
 * Registered automatically by the service provider:
 *   Gate::define('direct-grant', [DirectGrantPolicy::class, 'check']);
 *
 * Usage:
 *
 *   // Check against the current panel
 *   Gate::allows('direct-grant', 'app.documents.export');
 *
 *   // Check against an explicit panel
 *   Gate::allows('direct-grant', ['app.documents.export', 'app']);
 *
 *   // Inside a policy
 *   public function export(User $user, Document $document): bool
 *   {
 *       return Gate::allows('direct-grant', 'app.documents.export');
 *   }
 */
final class DirectGrantPolicy
{
    /**
     * @param  string|array<mixed>  $arguments  Key or [key, panel]
     */
    public function check(Authenticatable $user, string|array $arguments): bool
    {
        if (! method_exists($user, 'hasGrant')) {
            return false;
        }

        [$permissionKey, $panelId] = $this->parseArguments($arguments);

        return $user->hasGrant($permissionKey, $panelId);
    }

    /**
     * @param  string|array<mixed>  $arguments
     * @return array{0: string, 1: string|null}
     */
    private function parseArguments(string|array $arguments): array
    {
        if (is_string($arguments)) {
            return [$arguments, AzGuard::currentPanel()?->getId()];
        }

        $key = (string) ($arguments[0] ?? '');
        $panel = isset($arguments[1]) ? (string) $arguments[1] : AzGuard::currentPanel()?->getId();

        return [$key, $panel];
    }
}
