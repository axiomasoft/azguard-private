<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Catalog\CatalogCache;
use Illuminate\Console\Command;

/**
 * Removes the catalog cache; panels build their catalogs from their sources again.
 */
final class CatalogClearCommand extends Command
{
    /** @var string */
    protected $signature = 'azguard:catalog:clear';

    /** @var string */
    protected $description = 'Remove the AzGuard permission catalog cache';

    public function handle(CatalogCache $cache): int
    {
        $cache->clear();

        $this->components->info('AzGuard catalog cache cleared.');

        return self::SUCCESS;
    }
}
