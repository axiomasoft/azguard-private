<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources;

use AzGuard\Tests\Fixtures\Panels\BootsPanels;

/**
 * Boots panels like a host application, with the catalog cache file and build id the test gives.
 */
trait BootsWithCatalogCache
{
    use BootsPanels {
        BootsPanels::defineEnvironment as defineBootsPanelsEnvironment;
    }

    public static string $catalogCachePath = '';

    public static ?string $catalogBuildId = null;

    /**
     * Boots the panel providers with a fresh count of source reads.
     *
     * @param  list<class-string>  $providers
     */
    public function bootCatalogPanels(array $providers): void
    {
        StaticSource::$reads = [];
        $this->bootPanels(['providers' => $providers]);
    }

    protected function defineEnvironment($app): void
    {
        $this->defineBootsPanelsEnvironment($app);
        $app['config']->set('azguard.catalog.cache_path', self::$catalogCachePath);
        $app['config']->set('azguard.catalog.build_id', self::$catalogBuildId);
    }
}
