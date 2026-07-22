<?php

declare(strict_types=1);

namespace AzGuard\Runtime;

use Closure;

/**
 * Request-scoped cache for a user's scoped-role rows, keyed by
 * "{userId}|{entityClass}". Bound as a scoped container instance so it is
 * reset on every request (including under Laravel Octane), replacing the
 * mutable static cache that {@see HasScopedRoles} used to
 * keep — which would otherwise leak between requests in a long-running worker.
 *
 * @internal
 */
final class ScopedRoleCache
{
    /** @var array<string, mixed> */
    private array $store = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public function remember(string $key, Closure $resolve): mixed
    {
        return $this->store[$key] ??= $resolve();
    }

    public function flush(): void
    {
        $this->store = [];
    }
}
