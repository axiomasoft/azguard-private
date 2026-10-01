<?php

declare(strict_types=1);

namespace AzGuard\Runtime;

use AzGuard\Configuration\Config;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use Closure;

/**
 * Request-scoped cache for a user's scoped-role rows, keyed by
 * "{revision}|{userId}|{entityClass}". Bound as a scoped container instance so it is
 * reset on every request (including under Laravel Octane), replacing the
 * mutable static cache that {@see HasScopedRoles} used to
 * keep — which would otherwise leak between requests in a long-running worker.
 *
 * At any positive authorization-connection transaction level the cache is
 * bypassed for both reads and writes (D13).
 *
 * @internal
 */
final class ScopedRoleCache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function __construct(
        private readonly PermissionStateRevision $permissionState = new PermissionStateRevision,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public function remember(string $key, Closure $resolve): mixed
    {
        if ($this->permissionState->inTransaction()) {
            return $resolve();
        }

        $cacheKey = Config::cacheGeneration()."\0".$this->permissionState->current()."\0".$key;

        return $this->store[$cacheKey] ??= $resolve();
    }

    public function flush(): void
    {
        $this->store = [];
    }
}
