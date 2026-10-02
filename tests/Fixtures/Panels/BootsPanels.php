<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use Illuminate\Support\ServiceProvider;

/**
 * Boots a fresh application the way a host application does: panel providers come from `azguard.panels`, modules
 * are regular service providers.
 */
trait BootsPanels
{
    /** @var array<string, mixed> */
    private array $panelsConfig = [];

    /** @var list<class-string<ServiceProvider>> */
    private array $moduleProviders = [];

    /**
     * @param  array<string, mixed>  $panels  value of the `azguard.panels` configuration section
     * @param  list<class-string<ServiceProvider>>  $modules
     */
    protected function bootPanels(array $panels, array $modules = []): void
    {
        $this->panelsConfig = $panels;
        $this->moduleProviders = $modules;

        $this->reloadApplication();
    }

    protected function defineEnvironment($app): void
    {
        if ($this->panelsConfig !== []) {
            $app['config']->set('azguard.panels', $this->panelsConfig);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ...$this->moduleProviders];
    }
}
