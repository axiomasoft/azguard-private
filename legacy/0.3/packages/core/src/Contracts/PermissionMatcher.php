<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

/**
 * Strategy for matching a granted wildcard pattern against a concrete key.
 * Swappable via config('az-guard.matcher') so the wildcard grammar (and future
 * hierarchical/ReBAC backends) can change without touching consumer checks.
 *
 * @api
 */
interface PermissionMatcher
{
    /**
     * Whether a granted wildcard $pattern (e.g. 'app.documents.*') covers the
     * concrete resolved $key (e.g. 'app.documents.view').
     *
     * Implementations should memoize the compiled form of $pattern — it is
     * reused across many keys within a request.
     */
    public function matches(string $pattern, string $key): bool;
}
