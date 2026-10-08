<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Catalog\CatalogCache;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\PanelRegistry;

/**
 * `panels.policies` of the folder source: what discovery finds in the folders of the panel now is what the catalog
 * cache of this build recorded. A cache written before the folders changed is ignored, so its panel builds the
 * catalog at boot until the cache is written again.
 *
 * @internal
 */
final readonly class DiscoveryCached implements DoctorCheck
{
    private const array PARTS = ['definitions', 'bindings', 'roles', 'scopes', 'granted_to_all', 'sources', 'enums', 'files'];

    public function __construct(private PanelRegistry $registry, private CatalogCache $cache) {}

    public function key(): string
    {
        return 'panels.policies';
    }

    public function run(DoctorContext $context): iterable
    {
        $panel = $context->panel();
        $file = $this->cache->read();

        if ($panel === null || ($file['version'] ?? null) !== CatalogCache::VERSION || ($file['build_id'] ?? null) !== $this->registry->buildId()) {
            return;
        }
        $cached = $file['panels'][$panel->id()]['discovery'] ?? null;

        if (! is_array($cached)) {
            return;
        }

        yield from self::compare($panel->id(), $cached, $this->registry->discovery($panel->id()));
    }

    /**
     * @param  array<mixed>  $cached
     * @param  array<mixed>  $live
     * @return list<DoctorFinding>
     */
    public static function compare(string $panel, array $cached, array $live): array
    {
        $changed = array_values(array_filter(self::PARTS, static fn (string $part): bool => ($cached[$part] ?? null) !== ($live[$part] ?? null)));

        if ($changed === []) {
            return [];
        }

        return [DoctorFinding::warning('panels.policies', 'The catalog cache of panel '.$panel.' records another discovery than its folders give now ('
            .implode(', ', $changed).'): run azguard:catalog:cache.', 'panel:'.$panel, ['changed' => $changed])];
    }
}
