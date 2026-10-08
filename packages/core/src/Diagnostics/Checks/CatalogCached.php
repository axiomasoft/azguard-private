<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Catalog\CatalogCache;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\PanelRegistry;

/**
 * `catalog.cached`, for a production deployment: the catalog cache exists and holds the current catalog of every
 * selected panel, so no panel scans its folders at boot.
 *
 * @internal
 */
final readonly class CatalogCached implements DoctorCheck
{
    public function __construct(private PanelRegistry $registry, private CatalogCache $cache) {}

    public function key(): string
    {
        return 'catalog.cached';
    }

    public function run(DoctorContext $context): iterable
    {
        if (! $context->isProduction()) {
            return;
        }

        if ($this->cache->read() === []) {
            yield DoctorFinding::warning($this->key(), 'There is no catalog cache: every boot builds the catalogs from the sources. Run azguard:catalog:cache when deploying.');

            return;
        }

        foreach ($context->panels() as $panel) {
            if (! $this->registry->isCached($panel->id())) {
                yield DoctorFinding::warning($this->key(), 'The catalog cache does not hold the current catalog of panel '.$panel->id()
                    .': the code changed after azguard:catalog:cache ran. Run it again.', 'panel:'.$panel->id());
            }
        }
    }
}
