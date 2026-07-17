<?php

declare(strict_types=1);

namespace AzGuard\Registry\Builders;

use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use AzGuard\Registry\Contracts\PermissionDefinition;
use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;
use Closure;
use Override;

/**
 * Aggregates multiple PermissionCatalogBuilders into a single catalog.
 *
 * A key is the permission's identity. When the same key is produced by more
 * than one builder (e.g. Filament resource discovery AND a permission enum for
 * the same resource, or enum + policy on the same case) it is deduped
 * idempotently — the key is not redefined, only its display metadata
 * (group/label) may come from different sources. The most informative group
 * (non-null) wins so the Role UI still groups the permission sensibly.
 */
final class CompositePermissionCatalog implements PermissionCatalog
{
    /** @var array<string, array<string, PermissionDefinition>> panelId => key => definition */
    private array $definitions = [];

    private bool $built = false;

    /** @var Closure(): list<string> */
    private readonly Closure $panelIdsResolver;

    /**
     * @param  list<PermissionCatalogBuilder>  $builders
     * @param  list<string>|(Closure(): list<string>)  $panelIds  An array is
     *                                                            frozen; a closure is resolved lazily so panels registered after
     *                                                            boot are visible.
     */
    public function __construct(
        private readonly array $builders,
        array|Closure $panelIds,
    ) {
        $this->panelIdsResolver = $panelIds instanceof Closure
            ? $panelIds
            : static fn (): array => $panelIds;
    }

    private function ensureBuilt(): void
    {
        if ($this->built) {
            return;
        }

        foreach (($this->panelIdsResolver)() as $panelId) {
            $this->definitions[$panelId] = [];

            foreach ($this->builders as $builder) {
                if (! $builder->supports($panelId)) {
                    continue;
                }

                foreach ($builder->build($panelId) as $definition) {
                    $key = $definition->key();

                    if (isset($this->definitions[$panelId][$key])) {
                        // Same key from two builders is idempotent dedupe — the key
                        // is the permission's identity, group()/label() are only
                        // display metadata. Adopt a non-null group if the first
                        // source had none, so the Role UI groups it sensibly.
                        $existing = $this->definitions[$panelId][$key];

                        if ($existing->group() === null && $definition->group() !== null) {
                            $this->definitions[$panelId][$key] = $definition;
                        }

                        continue;
                    }

                    $this->definitions[$panelId][$key] = $definition;
                }
            }
        }

        $this->built = true;
    }

    #[Override]
    public function all(string $panelId): array
    {
        $this->ensureBuilt();

        return array_values($this->definitions[$panelId] ?? []);
    }

    #[Override]
    public function has(string $panelId, string $resolvedKey): bool
    {
        $this->ensureBuilt();

        return isset($this->definitions[$panelId][$resolvedKey]);
    }

    #[Override]
    public function get(string $panelId, string $resolvedKey): ?PermissionDefinition
    {
        $this->ensureBuilt();

        return $this->definitions[$panelId][$resolvedKey] ?? null;
    }

    #[Override]
    public function assert(string $panelId, string $resolvedKey): PermissionDefinition
    {
        $this->ensureBuilt();

        return $this->definitions[$panelId][$resolvedKey]
            ?? throw InvalidPermissionKeyException::forKey($resolvedKey, $panelId);
    }

    #[Override]
    public function groups(string $panelId): array
    {
        $this->ensureBuilt();

        $grouped = [];

        foreach ($this->definitions[$panelId] ?? [] as $definition) {
            $group = $definition->group() ?? 'Other';
            $grouped[$group][] = $definition;
        }

        ksort($grouped);

        return $grouped;
    }

    #[Override]
    public function panels(): array
    {
        return ($this->panelIdsResolver)();
    }

    /**
     * Reset the catalog cache (for tests or hot-reload in dev).
     */
    #[Override]
    public function flush(): void
    {
        $this->definitions = [];
        $this->built = false;
    }
}
