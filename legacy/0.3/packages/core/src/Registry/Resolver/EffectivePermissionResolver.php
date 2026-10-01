<?php

declare(strict_types=1);

namespace AzGuard\Registry\Resolver;

use AzGuard\Configuration\Config;
use AzGuard\Contracts\PermissionLayer;
use AzGuard\Contracts\PermissionResolverInterface;
use AzGuard\Permissions\CatalogKeyMatcher;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Contracts\PermissionDefinition;
use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Override;

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
        Config::assertAuthorizationConnectionsAligned();

        return $this->cache->rememberForRequest(
            SubjectIdentity::fromAuthenticatable($user),
            $panelId,
            fn (): PermissionSet => $this->resolve($user, $panelId),
            $this->layer?->cacheDiscriminator($panelId) ?? '',
        );
    }

    private function resolve(Authenticatable $user, string $panelId): PermissionSet
    {
        $set = PermissionSet::empty();

        foreach ($this->sources as $source) {
            $set = $set->merge($source->permissionsFor($user, $panelId));

            if ($set->isWildcard()) {
                return $set;
            }
        }

        // Optional post-aggregation layer (e.g. the context package applying its
        // merge strategy to the global set). Skipped on a global wildcard above —
        // a superadmin transcends contextual narrowing. Unlike the global-source
        // wildcard above, a wildcard surfacing HERE (from the layer) is NOT
        // trusted with an early return: it still passes through the catalog
        // filter below (defense-in-depth, C-13) — a context grant can never
        // legitimately carry '*' (ContextGrantBuilder::grant() rejects it at
        // write time), so a wildcard reaching this point is unexpected and must
        // not bypass catalog validation.
        if ($this->layer instanceof PermissionLayer) {
            $set = $this->layer->apply($set, $user, $panelId);
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
     * are kept only when the pattern actually covers at least one catalog key
     * under the bound matcher grammar (hierarchical by default; the deprecated
     * features.wildcard_permission flag restores the legacy grammar) — so a
     * meaningful grant survives but a stale 'app.nonsense.*' that matches
     * nothing is dropped. The bare global '*' is always dropped here: a real
     * superadmin wildcard short-circuits in resolve() before filtering, so one
     * surfacing at this point came from the layer and must not become a
     * superadmin grant (C-13/R7 defense-in-depth).
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
        $catalogKeys = array_map(
            static fn (PermissionDefinition $d): string => $d->key(),
            $this->catalog->all($panelId),
        );

        $filtered = $set->filter(function (string $key) use ($panelId, $catalogKeys): bool {
            if ($key === PermissionKey::WILDCARD) {
                return false;
            }

            if (! str_contains($key, PermissionKey::WILDCARD)) {
                return CatalogKeyMatcher::owns($this->catalog, $panelId, $key);
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
        $this->cache->forgetForUser(SubjectIdentity::fromAuthenticatable($user), $panelId);
    }

    /**
     * In-process-only flush for a specific user (call for transient,
     * within-request context switches — does not bump the durable epoch).
     */
    #[Override]
    public function forgetRequestCache(Authenticatable $user, string $panelId): void
    {
        $this->cache->forgetRequestCache(SubjectIdentity::fromAuthenticatable($user), $panelId);
    }

    /**
     * Sources in priority order (highest first), as sorted at construction.
     * Used by Authorizer::explain() (C-15) to attribute the winning source —
     * an off-hot-path diagnostic concern, not part of resolve().
     *
     * @return list<GrantSource>
     */
    public function sources(): array
    {
        return $this->sources;
    }
}
