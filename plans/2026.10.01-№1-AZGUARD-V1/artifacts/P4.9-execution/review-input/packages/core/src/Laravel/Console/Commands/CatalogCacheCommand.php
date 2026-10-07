<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Catalog\CatalogCache;
use AzGuard\Panels\PanelRegistry;
use Illuminate\Console\Command;

/**
 * Builds the static catalogs of all panels from their sources and writes them to the catalog cache.
 */
final class CatalogCacheCommand extends Command
{
    /** @var string */
    protected $signature = 'azguard:catalog:cache';

    /** @var string */
    protected $description = 'Cache the static permission catalogs of all AzGuard panels';

    public function handle(PanelRegistry $registry, CatalogCache $cache): int
    {
        $cache->write($registry->buildId(), $registry->snapshot());

        $this->components->info('AzGuard catalogs cached to '.$cache->path().'.');

        return self::SUCCESS;
    }
}
