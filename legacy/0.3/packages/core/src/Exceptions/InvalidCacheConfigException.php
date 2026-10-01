<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * Thrown at boot when az-guard.cache.expiration_time is null while
 * az-guard.cache.store is a persistent driver (C-04).
 *
 * PermissionCache's per-user epoch key (PermissionCache::epochKey()) is
 * bumped on every forgetForUser() call and re-put with the configured TTL
 * every time — an infinite TTL means that key, and every PermissionSet entry
 * it guards, never expires and never gets a natural garbage-collection point:
 * the store accumulates orphaned epoch/permission-set entries forever.
 */
final class InvalidCacheConfigException extends AzGuardException
{
    public static function nullTtlOnPersistentStore(string $store): self
    {
        return new self(sprintf(
            'az-guard.cache.expiration_time is null (infinite) while az-guard.cache.store [%s] is a '
            .'persistent driver. This leaves the per-user epoch key (and every PermissionSet entry it '
            .'guards) with no expiry, growing the store unbounded. Set an explicit TTL '
            .'(az-guard.cache.expiration_time), or use the "array" store for in-memory-only caching.',
            $store,
        ));
    }
}
