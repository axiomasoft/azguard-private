<?php

declare(strict_types=1);

namespace AzGuard\Registry\Resolver;

use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Support\Config;
use Closure;

/**
 * Per-request (and optional cross-request) cache for PermissionSet.
 *
 * Supports two layers:
 * 1. In-memory (always): $requestCache 2D array — lives for one HTTP request.
 * 2. Cross-request (optional): Laravel cache store (Redis / file / etc.).
 *
 * Octane-safe ONLY when bound as `scoped` (see AzGuardServiceProvider): the
 * in-memory $requestCache must not survive across requests on a reused worker,
 * or one user's resolved permissions would bleed into the next request.
 * Concurrent in-process calls compute the value twice and last writer wins
 * in the array — harmless and far safer than any locking strategy.
 *
 * @internal
 */
class PermissionCache
{
    /**
     * Prefix for every durable cache entry (permission set + per-user epoch).
     * A fixed internal namespace — Laravel's own `cache.prefix` already isolates
     * AzGuard's entries per app on a shared store, so this is not a config knob.
     */
    private const string KEY_PREFIX = 'azguard.perms';

    /**
     * @var array<string, array<string, array<string, PermissionSet>>>
     *                                                                 userId => panelId => discriminator => PermissionSet
     */
    private array $requestCache = [];

    /**
     * The optional $discriminator distinguishes entries that depend on
     * out-of-band state (e.g. the active workspace context) so two contexts on
     * the same panel never share a cached set. Supplied by a PermissionLayer.
     */
    public function rememberForRequest(int|string $userId, string $panelId, Closure $callback, string $discriminator = ''): PermissionSet
    {
        $uid = (string) $userId;

        if (isset($this->requestCache[$uid][$panelId][$discriminator])) {
            return $this->requestCache[$uid][$panelId][$discriminator];
        }

        $store = Config::cacheStore();

        $set = $store !== 'array'
            ? $this->loadFromStore($this->keyFor($userId, $panelId, $discriminator), $store, $callback)
            : $callback();

        return $this->requestCache[$uid][$panelId][$discriminator] = $set;
    }

    public function forgetForUser(int|string $userId, string $panelId): void
    {
        $uid = (string) $userId;

        // Drop every discriminator (all contexts) for this user+panel in-process.
        unset($this->requestCache[$uid][$panelId]);

        $store = Config::cacheStore();

        if ($store === 'array') {
            return;
        }

        // Bump the per-user epoch. Every key built from `keyFor()` embeds the
        // epoch, so incrementing it orphans ALL context-discriminated entries
        // (and the base entry) at once — no store-specific prefix enumeration
        // needed, and infinite-TTL entries stop being served immediately.
        //
        // `increment()` on a store where the key is still absent treats the
        // missing value as 0 and returns 1 — i.e. a no-op against the
        // `currentEpoch()` default of 1. Seed the key first (`add`, so a
        // concurrent forget never clobbers a genuine counter) to guarantee
        // the epoch strictly advances past the default on every forget.
        //
        // `increment()` does NOT refresh TTL on most stores, so a key seeded
        // once by `add()` would expire on its own while later PermissionSet
        // entries keep getting a fresh TTL every forget. Once the epoch key
        // expires, `currentEpoch()` falls back to 1 and the next forget
        // reseeds epoch 2 — colliding with a still-live epoch-2 entry from
        // before the expiry and serving a revoked grant until that entry's
        // own TTL runs out. Re-`put()` the resulting value on every call so
        // the epoch key's TTL never lags behind the entries it guards.
        $epochStore = cache()->store($store);
        $epochKey = $this->epochKey($userId, $panelId);
        $epochStore->add($epochKey, 1, Config::cacheTtl());
        $epoch = $epochStore->increment($epochKey);
        $epochStore->put($epochKey, $epoch, Config::cacheTtl());
    }

    /**
     * In-process-only invalidation: drops the cached PermissionSet(s) for this
     * user+panel from the request-local array WITHOUT bumping the durable
     * per-user epoch.
     *
     * Intended for transient, within-request context switches (e.g.
     * ContextGuard::checkInContext) where the previously-computed set for the
     * OLD discriminator must not leak into a check made under a different
     * (temporarily active) context, but there is no real grant/role change to
     * persist. Bumping the epoch here would invalidate the entire cross-request
     * cache for this user+panel on a persistent store on every single context
     * check — see forgetForUser() for the durable counterpart used on actual
     * grant/role mutations.
     */
    public function forgetRequestCache(int|string $userId, string $panelId): void
    {
        unset($this->requestCache[(string) $userId][$panelId]);
    }

    public function forgetAll(): void
    {
        $this->requestCache = [];
    }

    public function keyFor(int|string $userId, string $panelId, string $discriminator = ''): string
    {
        $epoch = $this->currentEpoch($userId, $panelId);
        $base = self::KEY_PREFIX.".{$userId}.{$panelId}.v{$epoch}";

        return $discriminator === '' ? $base : "{$base}.{$discriminator}";
    }

    /**
     * Current epoch for user+panel, read from the store (defaults to 1 —
     * never yet forgotten). Only meaningful when a persistent store is in
     * use; the array store never calls into this (see rememberForRequest).
     */
    private function currentEpoch(int|string $userId, string $panelId): int
    {
        $store = Config::cacheStore();

        if ($store === 'array') {
            return 1;
        }

        return (int) cache()->store($store)->get($this->epochKey($userId, $panelId), 1);
    }

    private function epochKey(int|string $userId, string $panelId): string
    {
        return self::KEY_PREFIX.".{$userId}.{$panelId}.epoch";
    }

    private function loadFromStore(string $cacheKey, string $store, Closure $callback): PermissionSet
    {
        $raw = cache()->store($store)->remember(
            $cacheKey,
            Config::cacheTtl(),
            fn () => $callback()->keys(),
        );

        if (is_array($raw)) {
            return PermissionSet::fromKeys($raw);
        }

        // Stale or incompatible cache entry — recompute and overwrite.
        $set = $callback();
        cache()->store($store)->put($cacheKey, $set->keys(), Config::cacheTtl());

        return $set;
    }
}
