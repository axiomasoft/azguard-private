<?php

declare(strict_types=1);

namespace AzGuard\Registry\Contracts;

use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;

/**
 * Registry of all known permissions.
 * Single source of truth: no key reaches the database without
 * passing through catalog->assert().
 *
 * @api
 */
interface PermissionCatalog
{
    /**
     * All definitions for a panel.
     *
     * @return list<PermissionDefinition>
     */
    public function all(string $panelId): array;

    /**
     * Whether the resolved key exists in the catalog.
     */
    public function has(string $panelId, string $resolvedKey): bool;

    /**
     * Returns the definition or null if not found.
     */
    public function get(string $panelId, string $resolvedKey): ?PermissionDefinition;

    /**
     * Returns the definition or throws if not found.
     *
     * @throws InvalidPermissionKeyException
     */
    public function assert(string $panelId, string $resolvedKey): PermissionDefinition;

    /**
     * Definitions grouped by group().
     *
     * @return array<string, list<PermissionDefinition>>
     */
    public function groups(string $panelId): array;

    /**
     * All registered panel IDs. Resolved lazily so a panel registered after
     * boot is visible (implementations must not freeze this at construction).
     *
     * @return list<string>
     */
    public function panels(): array;

    /**
     * Reset any built/cached state so the next access rebuilds from source —
     * for tests, dev hot-reload, or after a panel is registered at runtime.
     * Builders themselves are fixed at construction (constructor injection);
     * flush() re-runs the existing builders, it does not let you add new ones.
     */
    public function flush(): void;
}
