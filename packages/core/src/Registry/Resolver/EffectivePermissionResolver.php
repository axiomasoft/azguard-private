<?php

declare(strict_types=1);

namespace AzGuard\Registry\Resolver;

use AzGuard\Contracts\PermissionLayer;
use AzGuard\Contracts\PermissionResolverInterface;
use AzGuard\PermissionKey;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Contracts\PermissionDefinition;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Support\Config;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

/**
 * Main entry point for obtaining a PermissionSet for a user.
 *
 * Unions every GrantSource (highest priority first, short-circuiting on a
 * wildcard), applies the optional PermissionLayer (e.g. the context package),
 * filters the result through the PermissionCatalog (known keys only, or '*'),
 * and caches it per request via PermissionCache.
 *
 * @internal
 */
final readonly class EffectivePermissionResolver implements PermissionResolverInterface
{
    /** @var list<GrantSource> Sorted by priority DESC once at construction time. */
    private array $sources;

    /**
     * @param  iterable<GrantSource>  $sources
     * @param  PermissionLayer|null  $layer  Optional post-aggregation hook
     *                                       (e.g. the context package). Null = no layer.
     */
    public function __construct(
        private PermissionCatalog $catalog,
        iterable $sources,
        private PermissionCache $cache,
        private ?PermissionLayer $layer = null,
    ) {
        $this->sources = collect($sources)
            ->sortByDesc(fn (GrantSource $s): int => $s->priority())
            ->values()
            ->all();
    }

    #[Override]
    public function forUser(Authenticatable $user, string $panelId): PermissionSet
    {
        return $this->cache->rememberForRequest(
            $user->getAuthIdentifier(),
            $panelId,
            fn (): PermissionSet => $this->resolve($user, $panelId),
            $this->layer?->cacheDiscriminator($panelId) ?? '',
        );
    }

    private function resolve(Authenticatable $user, string $panelId): PermissionSet
    {
        $set = PermissionSet::empty();

        foreach ($this->sources as $source) {
            try {
                $set = $set->merge($source->permissionsFor($user, $panelId));
            } catch (Throwable $e) {
                if (Config::failOnSourceException()) {
                    throw $e;
                }

                Log::warning('AzGuard: grant source failed, skipping', [
                    'source' => $source::class,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($set->isWildcard()) {
                return $set;
            }
        }

        // Optional post-aggregation layer (e.g. the context package applying its
        // merge strategy to the global set). Skipped on a global wildcard above —
        // a superadmin transcends contextual narrowing.
        if ($this->layer instanceof PermissionLayer) {
            $set = $this->layer->apply($set, $user, $panelId);

            if ($set->isWildcard()) {
                return $set;
            }
        }

        if (! in_array($panelId, $this->catalog->panels(), true)) {
            return $set;
        }

        return $this->filterAgainstCatalog($set, $panelId);
    }

    /**
     * Drop keys the catalog does not know.
     *
     * Exact keys must exist in the catalog. Wildcard patterns ('app.docs.*')
     * are kept only when the wildcard feature is enabled AND the pattern
     * actually covers at least one catalog key — so a meaningful grant survives
     * but a stale 'app.nonsense.*' that matches nothing is dropped. With the
     * feature off, patterns are treated as unknown exact keys and removed.
     *
     * After an exact-match miss, a key is also checked against every dynamic
     * definition in the catalog (PermissionDefinition::isDynamic()) — e.g. a
     * concrete grant 'app.team.42.admin' matches the dynamic definition
     * 'app.team.{id}.admin', whose '{seg}' placeholder segments stand for
     * exactly one dotted segment. Non-dynamic definitions never participate
     * in this match — a bogus unknown key is still filtered out.
     */
    private function filterAgainstCatalog(PermissionSet $set, string $panelId): PermissionSet
    {
        $dynamicDefinitions = array_values(array_filter(
            $this->catalog->all($panelId),
            static fn (PermissionDefinition $d): bool => $d->isDynamic(),
        ));

        if (! Config::wildcardEnabled()) {
            $filtered = $set->filter(function (string $key) use ($panelId, $dynamicDefinitions): bool {
                if (str_contains($key, PermissionKey::WILDCARD)) {
                    return false;
                }

                return $this->catalog->has($panelId, $key)
                    || $this->matchesDynamicDefinition($key, $dynamicDefinitions);
            });
            $this->logDroppedKeys($set, $filtered, $panelId);

            return $filtered;
        }

        $catalogKeys = array_map(
            static fn (PermissionDefinition $d): string => $d->key(),
            $this->catalog->all($panelId),
        );

        $filtered = $set->filter(function (string $key) use ($panelId, $catalogKeys, $dynamicDefinitions): bool {
            if (! str_contains($key, PermissionKey::WILDCARD)) {
                if ($this->catalog->has($panelId, $key)) {
                    return true;
                }

                return $this->matchesDynamicDefinition($key, $dynamicDefinitions);
            }

            $pattern = PermissionSet::fromKeys([$key]);

            foreach ($catalogKeys as $catalogKey) {
                if ($pattern->matchesWildcard($catalogKey)) {
                    return true;
                }
            }

            return false;
        });

        $this->logDroppedKeys($set, $filtered, $panelId);

        return $filtered;
    }

    /**
     * Whether a concrete key (e.g. 'app.team.42.admin') matches at least one
     * dynamic definition (e.g. 'app.team.{id}.admin'). Each '{seg}' placeholder
     * segment matches exactly one dotted segment of the candidate key.
     *
     * @param  list<PermissionDefinition>  $dynamicDefinitions
     */
    private function matchesDynamicDefinition(string $key, array $dynamicDefinitions): bool
    {
        foreach ($dynamicDefinitions as $definition) {
            if ($this->matchesDynamicPattern($key, $definition->key())) {
                return true;
            }
        }

        return false;
    }

    private function matchesDynamicPattern(string $key, string $pattern): bool
    {
        $keySegments = explode(PermissionKey::SEPARATOR, $key);
        $patternSegments = explode(PermissionKey::SEPARATOR, $pattern);

        if (count($keySegments) !== count($patternSegments)) {
            return false;
        }

        foreach ($patternSegments as $index => $patternSegment) {
            $isPlaceholder = str_starts_with($patternSegment, '{') && str_ends_with($patternSegment, '}');

            if (! $isPlaceholder && $patternSegment !== $keySegments[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Surface keys that a grant/role declared but the catalog does not know —
     * almost always a typo in a role's permissions() or a stale DB grant. Debug
     * level so it aids diagnosis without noise; the keys are already dropped.
     */
    private function logDroppedKeys(PermissionSet $before, PermissionSet $after, string $panelId): void
    {
        $dropped = array_values(array_diff($before->keys(), $after->keys()));

        if ($dropped !== []) {
            Log::debug('AzGuard: dropped permission keys not in catalog', [
                'panel' => $panelId,
                'keys' => $dropped,
            ]);
        }
    }

    /**
     * Flush cache for a specific user (call when roles change).
     */
    #[Override]
    public function forgetForUser(Authenticatable $user, string $panelId): void
    {
        $this->cache->forgetForUser($user->getAuthIdentifier(), $panelId);
    }

    /**
     * In-process-only flush for a specific user (call for transient,
     * within-request context switches — does not bump the durable epoch).
     */
    #[Override]
    public function forgetRequestCache(Authenticatable $user, string $panelId): void
    {
        $this->cache->forgetRequestCache($user->getAuthIdentifier(), $panelId);
    }
}
