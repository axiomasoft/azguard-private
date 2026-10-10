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
     * Configuration a module reads from its own `register()`, which runs before `defineEnvironment()`.
     *
     * @var array<string, mixed>
     */
    private array $moduleConfig = [];

    /**
     * @param  array<string, mixed>  $panels  value of the `azguard.panels` configuration section
     * @param  list<class-string<ServiceProvider>>  $modules
     * @param  array<string, mixed>  $config  keys a module reads while it registers, such as `blog.azguard_panel`
     */
    protected function bootPanels(array $panels, array $modules = [], array $config = []): void
    {
        $this->panelsConfig = $panels;
        $this->moduleProviders = $modules;
        $this->moduleConfig = $config;

        $this->reloadApplication();
    }

    /**
     * Module providers register before `defineEnvironment()`, so their `register()` sees this configuration.
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        foreach ($this->moduleConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
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
